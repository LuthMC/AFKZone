<?php

declare(strict_types=1);

namespace Luthfi\AFKZone;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\sound\PopSound;
use pocketmine\world\particle\HeartParticle;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat as TF;

// Form API imports
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;

// ScoreHud support
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;

class Main extends PluginBase implements Listener {
    
    /** @var Config */
    private Config $afkZones;
    
    /** @var Config */
    private Config $config;
    
    /** @var Config */
    private Config $leaderboard;
    
    /** @var array */
    private array $playerAFKTimes = [];
    
    /** @var array */
    private array $playerSelection = [];
    
    /** @var array */
    private array $afkPlayers = [];
    
    /** @var array */
    private array $floatingTexts = [];
    
    /** @var string */
    private string $economyPlugin = "";
    
    /** @var mixed */
    private $economy = null;
    
    /** @var array */
    private array $scorehudTags = [];

    /**
     * Plugin startup logic
     */
    protected function onEnable(): void {
        // Register events
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        // Create default configuration
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        // Setup files
        @mkdir($this->getDataFolder() . "data/");
        $this->afkZones = new Config($this->getDataFolder() . "data/afkzones.yml", Config::YAML);
        $this->leaderboard = new Config($this->getDataFolder() . "data/leaderboard.yml", Config::YAML);
        
        // Setup economy
        $this->setupEconomy();
        
        // Update player AFK times and give rewards
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updatePlayerAFKTimes();
            $this->checkRewards();
        }), 20); // Run every second
        
        // Update leaderboards
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updateLeaderboards();
        }), 20 * 10); // Update every 10 seconds
        
        // Load existing floating texts
        $this->loadFloatingTexts();
        
        // Register ScoreHud tags if available
        if ($this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null) {
            $this->getServer()->getPluginManager()->registerEvents(new ScoreHudListener($this), $this);
            $this->getLogger()->info("ScoreHud support enabled!");
        }
    }
    
    /**
     * Setup economy plugin support
     */
    private function setupEconomy(): void {
        $configEconomy = $this->config->get("economy-plugin", "BedrockEconomy");
        
        // Try the configured economy plugin first
        if ($configEconomy === "BedrockEconomy") {
            if ($this->getServer()->getPluginManager()->getPlugin("BedrockEconomy") !== null) {
                $this->economyPlugin = "BedrockEconomy";
                $this->getLogger()->info("Using BedrockEconomy for economy support");
                return;
            }
            
            // Fall back to EconomyAPI if BedrockEconomy is not available
            if ($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") !== null) {
                $this->economyPlugin = "EconomyAPI";
                $this->getLogger()->info("BedrockEconomy not found, falling back to EconomyAPI");
                return;
            }
        } elseif ($configEconomy === "EconomyAPI") {
            if ($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") !== null) {
                $this->economyPlugin = "EconomyAPI";
                $this->getLogger()->info("Using EconomyAPI for economy support");
                return;
            }
            
            // Fall back to BedrockEconomy if EconomyAPI is not available
            if ($this->getServer()->getPluginManager()->getPlugin("BedrockEconomy") !== null) {
                $this->economyPlugin = "BedrockEconomy";
                $this->getLogger()->info("EconomyAPI not found, falling back to BedrockEconomy");
                return;
            }
        }
        
        $this->getLogger()->warning("No supported economy plugin found. Money rewards will not work.");
        $this->economyPlugin = "";
    }
    
    /**
     * Handle command execution
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() !== "afkzone") {
            return false;
        }
        
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game.");
            return true;
        }
        
        if (!isset($args[0])) {
            $this->sendHelp($sender);
            return true;
        }
        
        switch ($args[0]) {
            case "ui":
                $this->openMainUI($sender);
                break;
                
            case "wand":
                if (!$sender->hasPermission("afkzone.admin")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                $wand = VanillaItems::WOODEN_AXE();
                $wand->setCustomName(TF::GOLD . "AFK Zone Wand");
                $wand->setLore([
                    TF::YELLOW . "Left click: Set Position 1",
                    TF::YELLOW . "Right click: Set Position 2"
                ]);
                
                $sender->getInventory()->addItem($wand);
                $sender->sendMessage(TF::GREEN . "You have received the AFK Zone wand.");
                break;
                
            case "create":
                if (!$sender->hasPermission("afkzone.admin")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED . "Usage: /afkzone create <name>");
                    return true;
                }
                
                $name = $args[1];
                
                if (!isset($this->playerSelection[$sender->getName()]["pos1"]) || !isset($this->playerSelection[$sender->getName()]["pos2"])) {
                    $sender->sendMessage(TF::RED . "Please select two positions using the AFK Zone wand first.");
                    return true;
                }
                
                if ($this->afkZones->exists($name)) {
                    $sender->sendMessage(TF::RED . "An AFK Zone with that name already exists.");
                    return true;
                }
                
                $pos1 = $this->playerSelection[$sender->getName()]["pos1"];
                $pos2 = $this->playerSelection[$sender->getName()]["pos2"];
                
                $this->afkZones->set($name, [
                    "world" => $pos1->getWorld()->getFolderName(),
                    "pos1" => [$pos1->getX(), $pos1->getY(), $pos1->getZ()],
                    "pos2" => [$pos2->getX(), $pos2->getY(), $pos2->getZ()]
                ]);
                
                $this->afkZones->save();
                $sender->sendMessage(TF::GREEN . "AFK Zone '$name' created successfully.");
                break;
                
            case "delete":
                if (!$sender->hasPermission("afkzone.admin")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED . "Usage: /afkzone delete <name>");
                    return true;
                }
                
                $name = $args[1];
                
                if (!$this->afkZones->exists($name)) {
                    $sender->sendMessage(TF::RED . "AFK Zone '$name' does not exist.");
                    return true;
                }
                
                $this->afkZones->remove($name);
                $this->afkZones->save();
                $sender->sendMessage(TF::GREEN . "AFK Zone '$name' has been deleted.");
                break;
                
            case "list":
                if (!$sender->hasPermission("afkzone.admin")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                $zones = $this->afkZones->getAll();
                
                if (empty($zones)) {
                    $sender->sendMessage(TF::YELLOW . "There are no AFK Zones created yet.");
                    return true;
                }
                
                $sender->sendMessage(TF::GREEN . "=== AFK Zones ===");
                foreach ($zones as $zoneName => $zoneData) {
                    $sender->sendMessage(TF::YELLOW . $zoneName);
                }
                break;
                
            default:
                $this->sendHelp($sender);
                break;
        }
        
        return true;
    }
    
    /**
     * Send help message to player
     */
    private function sendHelp(Player $player): void {
        $player->sendMessage(TF::GREEN . "=== AFK Zone Commands ===");
        $player->sendMessage(TF::YELLOW . "/afkzone ui " . TF::WHITE . "- Open the AFK Zone UI");
        
        if ($player->hasPermission("afkzone.admin")) {
            $player->sendMessage(TF::YELLOW . "/afkzone wand " . TF::WHITE . "- Get the AFK Zone selection wand");
            $player->sendMessage(TF::YELLOW . "/afkzone create <name> " . TF::WHITE . "- Create a new AFK Zone");
            $player->sendMessage(TF::YELLOW . "/afkzone delete <name> " . TF::WHITE . "- Delete an AFK Zone");
            $player->sendMessage(TF::YELLOW . "/afkzone list " . TF::WHITE . "- List all AFK Zones");
        }
    }
    
    /**
     * Open the main UI for players
     */
    private function openMainUI(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0: // Leaderboard
                    if ($player->hasPermission("afkzone.admin")) {
                        $this->openLeaderboardUI($player);
                    } else {
                        $player->sendMessage(TF::RED . "You don't have permission to manage leaderboards.");
                    }
                    break;
                    
                case 1: // List Zones
                    $this->openZoneListUI($player);
                    break;
                    
                case 2: // Create Zone (Admin Only)
                    if ($player->hasPermission("afkzone.admin")) {
                        $player->sendMessage(TF::YELLOW . "Use /afkzone wand to get the selection tool first.");
                    } else {
                        $player->sendMessage(TF::RED . "You don't have permission to create zones.");
                    }
                    break;
            }
        });
        
        $form->setTitle("AFK Zone Management");
        $form->setContent("Select an option below:");
        
        if ($player->hasPermission("afkzone.admin")) {
            $form->addButton("Leaderboard Settings");
        } else {
            $form->addButton("View Leaderboard");
        }
        
        $form->addButton("List Zones");
        
        if ($player->hasPermission("afkzone.admin")) {
            $form->addButton("Create Zone\n(Get wand first)");
        }
        
        $player->sendForm($form);
    }
    
    /**
     * Open leaderboard management UI
     */
    private function openLeaderboardUI(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0: // Create floating text
                    $this->createFloatingText($player);
                    break;
                    
                case 1: // Remove floating text
                    $this->removeFloatingText($player);
                    break;
                    
                case 2: // Back to main menu
                    $this->openMainUI($player);
                    break;
            }
        });
        
        $form->setTitle("Leaderboard Management");
        $form->setContent("Manage floating text leaderboards:");
        $form->addButton("Create Floating Text");
        $form->addButton("Remove Floating Text");
        $form->addButton("Back to Main Menu");
        
        $player->sendForm($form);
    }
    
    /**
     * Create floating text leaderboard
     */
    private function createFloatingText(Player $player): void {
        $form = new CustomForm(function (Player $player, ?array $data) {
            if ($data === null) {
                $this->openLeaderboardUI($player);
                return;
            }
            
            $title = $data[0] ?? "AFK Leaderboard";
            $maxEntries = (int) ($data[1] ?? 10);
            
            $this->leaderboard->set($player->getName(), [
                "title" => $title,
                "position" => [
                    "x" => $player->getPosition()->getX(),
                    "y" => $player->getPosition()->getY() + 1.5, // Slightly above player
                    "z" => $player->getPosition()->getZ(),
                    "world" => $player->getWorld()->getFolderName()
                ],
                "maxEntries" => $maxEntries
            ]);
            
            $this->leaderboard->save();
            $this->loadFloatingText($player->getName());
            
            $player->sendMessage(TF::GREEN . "Floating text leaderboard created successfully!");
            $this->openLeaderboardUI($player);
        });
        
        $form->setTitle("Create Floating Text");
        $form->addInput("Title", "AFK Leaderboard", "AFK Leaderboard");
        $form->addInput("Max Entries", "10", "10");
        
        $player->sendForm($form);
    }
    
    /**
     * Remove floating text UI
     */
    private function removeFloatingText(Player $player): void {
        $leaderboards = $this->leaderboard->getAll();
        
        if (empty($leaderboards)) {
            $player->sendMessage(TF::YELLOW . "There are no floating text leaderboards to remove.");
            $this->openLeaderboardUI($player);
            return;
        }
        
        $form = new SimpleForm(function (Player $player, ?int $data) use ($leaderboards) {
            if ($data === null) {
                $this->openLeaderboardUI($player);
                return;
            }
            
            $leaderboardNames = array_keys($leaderboards);
            $selected = $leaderboardNames[$data] ?? null;
            
            if ($selected !== null) {
                // Remove the floating text entity
                if (isset($this->floatingTexts[$selected])) {
                    $this->floatingTexts[$selected]->close();
                    unset($this->floatingTexts[$selected]);
                }
                
                // Remove from config
                $this->leaderboard->remove($selected);
                $this->leaderboard->save();
                
                $player->sendMessage(TF::GREEN . "Floating text leaderboard removed successfully!");
            }
            
            $this->openLeaderboardUI($player);
        });
        
        $form->setTitle("Remove Floating Text");
        $form->setContent("Select a leaderboard to remove:");
        
        foreach (array_keys($leaderboards) as $name) {
            $form->addButton($name);
        }
        
        $player->sendForm($form);
    }
    
    /**
     * Open zone list UI
     */
    private function openZoneListUI(Player $player): void {
        $zones = $this->afkZones->getAll();
        
        if (empty($zones)) {
            $player->sendMessage(TF::YELLOW . "There are no AFK Zones created yet.");
            $this->openMainUI($player);
            return;
        }
        
        $form = new SimpleForm(function (Player $player, ?int $data) use ($zones) {
            if ($data === null) {
                $this->openMainUI($player);
                return;
            }
            
            $zoneNames = array_keys($zones);
            $selectedZone = $zoneNames[$data] ?? null;
            
            if ($selectedZone !== null && $player->hasPermission("afkzone.admin")) {
                $this->openZoneDetailsUI($player, $selectedZone);
            } else {
                $this->openMainUI($player);
            }
        });
        
        $form->setTitle("AFK Zones");
        $form->setContent("Select a zone to view details" . ($player->hasPermission("afkzone.admin") ? " or manage" : "") . ":");
        
        foreach (array_keys($zones) as $zoneName) {
            $form->addButton($zoneName);
        }
        
        $player->sendForm($form);
    }
    
    /**
     * Open zone details UI for admins
     */
    private function openZoneDetailsUI(Player $player, string $zoneName): void {
        $form = new SimpleForm(function (Player $player, ?int $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneListUI($player);
                return;
            }
            
            switch ($data) {
                case 0: // Teleport to zone
                    $this->teleportToZone($player, $zoneName);
                    break;
                    
                case 1: // Delete zone
                    $this->afkZones->remove($zoneName);
                    $this->afkZones->save();
                    $player->sendMessage(TF::GREEN . "AFK Zone '$zoneName' has been deleted.");
                    $this->openZoneListUI($player);
                    break;
                    
                case 2: // Back to list
                    $this->openZoneListUI($player);
                    break;
            }
        });
        
        $form->setTitle("Zone: $zoneName");
        $form->setContent("What would you like to do with this zone?");
        $form->addButton("Teleport to Zone");
        $form->addButton("Delete Zone");
        $form->addButton("Back to List");
        
        $player->sendForm($form);
    }
    
    /**
     * Teleport player to an AFK zone's center
     */
    private function teleportToZone(Player $player, string $zoneName): void {
        $zoneData = $this->afkZones->get($zoneName);
        
        if ($zoneData === null) {
            $player->sendMessage(TF::RED . "Zone not found.");
            return;
        }
        
        $world = $this->getServer()->getWorldManager()->getWorldByName($zoneData["world"]);
        
        if ($world === null) {
            $player->sendMessage(TF::RED . "Zone world not loaded.");
            return;
        }
        
        $pos1 = $zoneData["pos1"];
        $pos2 = $zoneData["pos2"];
        

      /**
     * Give money to a player
     */
    private function giveMoney(Player $player, int $amount): void {
        if ($amount <= 0) {
            return;
        }
        
        if ($this->economyPlugin === "BedrockEconomy") {
            $bedrockEconomy = $this->getServer()->getPluginManager()->getPlugin("BedrockEconomy");
            if ($bedrockEconomy !== null) {
                $bedrockEconomy->giveMoney($player, $amount);
                $player->sendMessage(TF::GREEN . "You received $" . number_format($amount) . " for staying in the AFK Zone!");
            }
        } elseif ($this->economyPlugin === "EconomyAPI") {
            $economyAPI = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if ($economyAPI !== null) {
                $economyAPI->addMoney($player, $amount);
                $player->sendMessage(TF::GREEN . "You received $" . number_format($amount) . " for staying in the AFK Zone!");
            }
        }
    }
    
    /**
     * Give item to a player
     */
    private function giveItem(Player $player, string $itemName, int $amount): void {
        if (empty($itemName) || $amount <= 0) {
            return;
        }
        
        $item = null;
        
        // Basic item mapping - add more items as needed
        switch (strtolower($itemName)) {
            case "diamond":
                $item = VanillaItems::DIAMOND()->setCount($amount);
                break;
                
            case "iron_ingot":
                $item = VanillaItems::IRON_INGOT()->setCount($amount);
                break;
                
            case "gold_ingot":
                $item = VanillaItems::GOLD_INGOT()->setCount($amount);
                break;
                
            case "emerald":
                $item = VanillaItems::EMERALD()->setCount($amount);
                break;
                
            // Add more items as needed
        }
        
        if ($item !== null) {
            $player->getInventory()->addItem($item);
            $player->sendMessage(TF::GREEN . "You received " . $amount . "x " . $itemName . " for staying in the AFK Zone!");
        }
    }
    
    /**
     * Execute a command as a reward
     */
    private function executeCommand(Player $player, string $command): void {
        if (empty($command)) {
            return;
        }
        
        // Replace placeholders
        $command = str_replace("{player}", $player->getName(), $command);
        
        // Execute command as console
        $this->getServer()->dispatchCommand(new \pocketmine\console\ConsoleCommandSender($this->getServer(), $this->getServer()->getLanguage()), $command);
    }
    
    /**
     * Load all floating text leaderboards
     */
    private function loadFloatingTexts(): void {
        foreach ($this->leaderboard->getAll() as $name => $data) {
            $this->loadFloatingText($name);
        }
    }
    
    /**
     * Load a specific floating text leaderboard
     */
    private function loadFloatingText(string $name): void {
        $data = $this->leaderboard->get($name);
        
        if ($data === null) {
            return;
        }
        
        $worldName = $data["position"]["world"];
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        
        if ($world === null) {
            $this->getLogger()->warning("Could not load floating text for '$name' because world '$worldName' is not loaded.");
            return;
        }
        
        $position = new Vector3(
            $data["position"]["x"],
            $data["position"]["y"],
            $data["position"]["z"]
        );
        
        // Create or update floating text
        if (isset($this->floatingTexts[$name])) {
            $this->floatingTexts[$name]->close();
        }
        
        // Create FloatingTextParticle
        $title = $data["title"];
        $maxEntries = $data["maxEntries"];
        
        // Generate initial text
        $text = $this->generateLeaderboardText($title, $maxEntries);
        
        // Create entity for floating text
        $entity = new FloatingTextEntity($position, $text, $world);
        $entity->spawnToAll();
        
        $this->floatingTexts[$name] = $entity;
    }
    
    /**
     * Generate leaderboard text
     */
    private function generateLeaderboardText(string $title, int $maxEntries): string {
        $text = TF::BOLD . TF::YELLOW . $title . TF::RESET . "\n";
        $text .= TF::GRAY . "Updated: " . date("H:i:s") . "\n\n";
        
        // Sort players by AFK time
        $sortedPlayers = $this->playerAFKTimes;
        arsort($sortedPlayers);
        
        $count = 0;
        foreach ($sortedPlayers as $playerName => $time) {
            if ($count >= $maxEntries) {
                break;
            }
            
            $timeFormatted = $this->formatTime($time);
            $text .= TF::AQUA . "#" . ($count + 1) . " " . TF::WHITE . $playerName . " - " . TF::YELLOW . $timeFormatted . "\n";
            $count++;
        }
        
        if ($count === 0) {
            $text .= TF::GRAY . "No players have used AFK zones yet.";
        }
        
        return $text;
    }
    
    /**
     * Update all leaderboards
     */
    private function updateLeaderboards(): void {
        foreach ($this->floatingTexts as $name => $entity) {
            $data = $this->leaderboard->get($name);
            
            if ($data === null) {
                continue;
            }
            
            $title = $data["title"];
            $maxEntries = $data["maxEntries"];
            
            // Update text
            $newText = $this->generateLeaderboardText($title, $maxEntries);
            $entity->setText($newText);
        }
    }
    
    /**
     * Get a player's AFK time
     */
    public function getPlayerAFKTime(string $playerName): int {
        return $this->playerAFKTimes[$playerName] ?? 0;
    }
    
    /**
     * Get formatted AFK time for a player
     */
    public function getFormattedAFKTime(string $playerName): string {
        $time = $this->playerAFKTimes[$playerName] ?? 0;
        return $this->formatTime($time);
    }
}

/**
 * ScoreHud integration listener
 */
class ScoreHudListener implements Listener {
    
    /** @var Main */
    private Main $plugin;
    
    /**
     * Constructor
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    /**
     * Handle ScoreHud tag resolution
     */
    public function onTagResolve(TagsResolveEvent $event): void {
        $tag = $event->getTag();
        
        if ($tag->getName() === "afkzone.time") {
            $player = $event->getPlayer();
            $time = $this->plugin->getFormattedAFKTime($player->getName());
            $tag->setValue($time);
        }
    }
}

/**
 * Custom entity for floating text
 */
class FloatingTextEntity {
    
    /** @var Vector3 */
    private Vector3 $position;
    
    /** @var string */
    private string $text;
    
    /** @var \pocketmine\world\World */
    private \pocketmine\world\World $world;
    
    /** @var int */
    private int $entityId;
    
    /**
     * Constructor
     */
    public function __construct(Vector3 $position, string $text, \pocketmine\world\World $world) {
        $this->position = $position;
        $this->text = $text;
        $this->world = $world;
        $this->entityId = \pocketmine\entity\Entity::nextRuntimeId();
        
        $this->spawnToAll();
    }
    
    /**
     * Spawn the floating text to all players
     */
    public function spawnToAll(): void {
        foreach ($this->world->getPlayers() as $player) {
            $this->spawnTo($player);
        }
    }
    
    /**
     * Spawn the floating text to a specific player
     */
    public function spawnTo(Player $player): void {
        // Implementation using packets to create floating text
        // This is a simplified implementation
        // In a real plugin, you might need to use more complex packet handling
        
        // For simplicity, we'll send a title to simulate the floating text
        if ($player->getWorld() === $this->world) {
            $player->sendTip($this->text);
        }
    }
    
    /**
     * Update the text
     */
    public function setText(string $text): void {
        $this->text = $text;
        $this->spawnToAll();
    }
    
    /**
     * Close/remove the floating text
     */
    public function close(): void {
        // Implementation to remove the floating text
        // In a real plugin, you would send packets to remove the entity
    }
}