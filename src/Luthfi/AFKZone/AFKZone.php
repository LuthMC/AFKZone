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
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;
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

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        
        @mkdir($this->getDataFolder() . "data/");
        $this->afkZones = new Config($this->getDataFolder() . "data/afkzones.yml", Config::YAML);
        $this->leaderboard = new Config($this->getDataFolder() . "data/leaderboard.yml", Config::YAML);
        
        $this->setupEconomy();
        
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updatePlayerAFKTimes();
            $this->checkRewards();
        }), 20);
        
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updateLeaderboards();
        }), 20 * 60);
        
        $this->loadFloatingTexts();
        
        if ($this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null) {
            $this->getServer()->getPluginManager()->registerEvents(new ScoreHudListener($this), $this);
        }
    }
    
    private function setupEconomy(): void {
        $configEconomy = $this->config->get("economy-plugin", "BedrockEconomy");
        
        if ($configEconomy === "BedrockEconomy") {
            if ($this->getServer()->getPluginManager()->getPlugin("BedrockEconomy") !== null) {
                $this->economyPlugin = "BedrockEconomy";
                $this->getLogger()->info("Using BedrockEconomy for economy support");
                return;
            }
            
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
            
            if ($this->getServer()->getPluginManager()->getPlugin("BedrockEconomy") !== null) {
                $this->economyPlugin = "BedrockEconomy";
                $this->getLogger()->info("EconomyAPI not found, falling back to BedrockEconomy");
                return;
            }
        }
        
        $this->getLogger()->warning("No supported economy plugin found. Money rewards will not work.");
        $this->economyPlugin = "";
    }
    
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
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                $wand = VanillaItems::WOODEN_AXE();
                $wand->setCustomName(TF::GOLD . "AFKZone Wand");
                $wand->setLore([
                    TF::YELLOW . "Left click: Set Position 1",
                    TF::YELLOW . "Right click: Set Position 2"
                ]);
                
                $sender->getInventory()->addItem($wand);
                $sender->sendMessage(TF::GREEN . "You have received the AFK Zone wand.");
                break;
                
            case "create":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED . "Usage: /afkzone create <name>");
                    return true;
                }
                
                $name = $args[1];
                
                if (!isset($this->playerSelection[$sender->getName()]["pos1"]) || !isset($this->playerSelection[$sender->getName()]["pos2"])) {
                    $sender->sendMessage(TF::RED . "Please select two positions using the AFKZone wand first.");
                    return true;
                }
                
                if ($this->afkZones->exists($name)) {
                    $sender->sendMessage(TF::RED . "An AFKZone with that name already exists.");
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
                $sender->sendMessage(TF::GREEN . "AFKZone '$name' created successfully.");
                break;
                
            case "delete":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                if (!isset($args[1])) {
                    $sender->sendMessage(TF::RED . "Usage: /afkzone delete <name>");
                    return true;
                }
                
                $name = $args[1];
                
                if (!$this->afkZones->exists($name)) {
                    $sender->sendMessage(TF::RED . "AFKZone '$name' does not exist.");
                    return true;
                }
                
                $this->afkZones->remove($name);
                $this->afkZones->save();
                $sender->sendMessage(TF::GREEN . "AFKZone '$name' has been deleted.");
                break;
                
            case "list":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                
                $zones = $this->afkZones->getAll();
                
                if (empty($zones)) {
                    $sender->sendMessage(TF::YELLOW . "There are no AFKZones created yet.");
                    return true;
                }
                
                $sender->sendMessage(TF::GREEN . "=---= AFKZones =---=");
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
    
    private function sendHelp(Player $player): void {
        $player->sendMessage(TF::GREEN . "=== AFKZone Commands ===");
        $player->sendMessage(TF::YELLOW . "/afkzone ui " . TF::WHITE . "- Open the AFKZone UI");
        
        if ($player->hasPermission("afkzone.cmd")) {
            $player->sendMessage(TF::YELLOW . "/afkzone wand " . TF::WHITE . "- Get the AFKZone wand");
            $player->sendMessage(TF::YELLOW . "/afkzone create <name> " . TF::WHITE . "- Create a new AFKZone");
            $player->sendMessage(TF::YELLOW . "/afkzone delete <name> " . TF::WHITE . "- Delete an AFKZone");
            $player->sendMessage(TF::YELLOW . "/afkzone list " . TF::WHITE . "- List all AFKZones");
        }
    }
    
    private function openMainUI(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0:
                    if ($player->hasPermission("afkzone.cmd")) {
                        $this->openLeaderboardUI($player);
                    } else {
                        $player->sendMessage(TF::RED . "You don't have permission to manage leaderboards.");
                    }
                    break;
                    
                case 1:
                    $this->openZoneListUI($player);
                    break;
                    
                case 2:
                    if ($player->hasPermission("afkzone.cmd")) {
                        $player->sendMessage(TF::YELLOW . "Use /afkzone wand to get the selection tool first.");
                    } else {
                        $player->sendMessage(TF::RED . "You don't have permission to create zones.");
                    }
                    break;
            }
        });
        
        $form->setTitle("AFKZone Management");
        $form->setContent("Select an option below:");
        
        if ($player->hasPermission("afkzone.cmd")) {
            $form->addButton("Leaderboard Settings");
        } else {
            $form->addButton("View Leaderboard");
        }
        
        $form->addButton("List AFKZone");
        
        if ($player->hasPermission("afkzone.cmd")) {
            $form->addButton("Create Zone\n(Get wand first)");
        }
        
        $player->sendForm($form);
    }
    
    private function openLeaderboardUI(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data) {
            if ($data === null) {
                return;
            }
            
            switch ($data) {
                case 0:
                    $this->createFloatingText($player);
                    break;
                    
                case 1:
                    $this->removeFloatingText($player);
                    break;
                    
                case 2:
                    $this->openMainUI($player);
                    break;
            }
        });
        
        $form->setTitle("Leaderboard Settings");
        $form->addButton("Create Leaderboard");
        $form->addButton("Remove Leaderboard");
        $form->addButton("Back to Main Menu");
        
        $player->sendForm($form);
    }
    
    private function createFloatingText(Player $player): void {
        $form = new CustomForm(function (Player $player, ?array $data) {
            if ($data === null) {
                $this->openLeaderboardUI($player);
                return;
            }
            
            $title = $data[0] ?? "AFKZone Leaderboard";
            $maxEntries = (int) ($data[1] ?? 10);
            
            $this->leaderboard->set($player->getName(), [
                "title" => $title,
                "position" => [
                    "x" => $player->getPosition()->getX(),
                    "y" => $player->getPosition()->getY() + 1.5,
                    "z" => $player->getPosition()->getZ(),
                    "world" => $player->getWorld()->getFolderName()
                ],
                "maxEntries" => $maxEntries
            ]);
            
            $this->leaderboard->save();
            $this->loadFloatingText($player->getName());
            
            $player->sendMessage(TF::GREEN . "Leaderboard created successfully!");
            $this->openLeaderboardUI($player);
        });
        
        $form->setTitle("Create Floating Text");
        $form->addInput("Title", "Leaderboard", "AFKZone Leaderboard");
        $form->addInput("Max Entries", "10", "10");
        
        $player->sendForm($form);
    }
    
    private function removeFloatingText(Player $player): void {
        $leaderboards = $this->leaderboard->getAll();
        
        if (empty($leaderboards)) {
            $player->sendMessage(TF::YELLOW . "There are no leaderboards to remove.");
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
                if (isset($this->floatingTexts[$selected])) {
                    $this->floatingTexts[$selected]->close();
                    unset($this->floatingTexts[$selected]);
                }
                
                $this->leaderboard->remove($selected);
                $this->leaderboard->save();
                
                $player->sendMessage(TF::GREEN . "Leaderboard removed successfully!");
            }
            
            $this->openLeaderboardUI($player);
        });
        
        $form->setTitle("Remove Leaderboard");
        $form->setContent("Select a leaderboard to remove:");
        
        foreach (array_keys($leaderboards) as $name) {
            $form->addButton($name);
        }
        
        $player->sendForm($form);
    }
    
    private function openZoneListUI(Player $player): void {
        $zones = $this->afkZones->getAll();
        
        if (empty($zones)) {
            $player->sendMessage(TF::YELLOW . "There are no AFKZone created yet.");
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
            
            if ($selectedZone !== null && $player->hasPermission("afkzone.cmd")) {
                $this->openZoneDetailsUI($player, $selectedZone);
            } else {
                $this->openMainUI($player);
            }
        });
        
        $form->setTitle("AFKZone");
        $form->setContent("Select a zone to view details" . ($player->hasPermission("afkzone.cmd") ? " or manage" : "") . ":");
        
        foreach (array_keys($zones) as $zoneName) {
            $form->addButton($zoneName);
        }
        
        $player->sendForm($form);
    }
    
    private function openZoneDetailsUI(Player $player, string $zoneName): void {
        $form = new SimpleForm(function (Player $player, ?int $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneListUI($player);
                return;
            }
            
            switch ($data) {
                case 0:
                    $this->teleportToZone($player, $zoneName);
                    break;
                    
                case 1:
                    $this->afkZones->remove($zoneName);
                    $this->afkZones->save();
                    $player->sendMessage(TF::GREEN . "AFKZone '$zoneName' has been deleted.");
                    $this->openZoneListUI($player);
                    break;
                    
                case 2:
                    $this->openZoneListUI($player);
                    break;
            }
        });
        
        $form->setTitle("AFKZone: $zoneName");
        $form->setContent("What would you like to do with this zone?");
        $form->addButton("Teleport to AFKZone");
        $form->addButton("Delete AFKZone");
        $form->addButton("Back to List");
        
        $player->sendForm($form);
    }
    
    private function teleportToZone(Player $player, string $zoneName): void {
        $zoneData = $this->afkZones->get($zoneName);
        
        if ($zoneData === null) {
            $player->sendMessage(TF::RED . "AFKZone not found.");
            return;
        }
        
        $world = $this->getServer()->getWorldManager()->getWorldByName($zoneData["world"]);
        
        if ($world === null) {
            $player->sendMessage(TF::RED . "AFKZone world not loaded.");
            return;
        }
        
        $pos1 = $zoneData["pos1"];
        $pos2 = $zoneData["pos2"];
    }

    private function giveMoney(Player $player, int $amount): void {
        if ($amount <= 0) {
            return;
        }
        
        if ($this->economyPlugin === "BedrockEconomy") {
            $bedrockEconomy = $this->getServer()->getPluginManager()->getPlugin("BedrockEconomy");
            if ($bedrockEconomy !== null) {
                $bedrockEconomy->giveMoney($player, $amount);
                $player->sendMessage(TF::GREEN . "You received $" . number_format($amount) . " for staying in the AFKZone!");
            }
        } elseif ($this->economyPlugin === "EconomyAPI") {
            $economyAPI = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
            if ($economyAPI !== null) {
                $economyAPI->addMoney($player, $amount);
                $player->sendMessage(TF::GREEN . "You received $" . number_format($amount) . " for staying in the AFKZone!");
            }
        }
    }
    
    private function giveItem(Player $player, string $itemName, int $amount): void {
        if (empty($itemName) || $amount <= 0) {
            return;
        }
        
        $item = null;
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
        }
        
        if ($item !== null) {
            $player->getInventory()->addItem($item);
            $player->sendMessage(TF::GREEN . "You received " . $amount . "x " . $itemName . " for staying in the AFK Zone!");
        }
    }
    
    private function executeCommand(Player $player, string $command): void {
        if (empty($command)) {
            return;
        }
        
        $command = str_replace("{player}", $player->getName(), $command);
        $this->getServer()->dispatchCommand(new \pocketmine\console\ConsoleCommandSender($this->getServer(), $this->getServer()->getLanguage()), $command);
    }
    
    private function loadFloatingTexts(): void {
        foreach ($this->leaderboard->getAll() as $name => $data) {
            $this->loadFloatingText($name);
        }
    }
    
    private function loadFloatingText(string $name): void {
        $data = $this->leaderboard->get($name);
        
        if ($data === null) {
            return;
        }
        
        $worldName = $data["position"]["world"];
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        
        if ($world === null) {
            $this->getLogger()->warning("Could not load leaderboard for '$name' because world '$worldName' is not loaded.");
            return;
        }
        
        $position = new Vector3(
            $data["position"]["x"],
            $data["position"]["y"],
            $data["position"]["z"]
        );
        
        if (isset($this->floatingTexts[$name])) {
            $this->floatingTexts[$name]->close();
        }
        
        $title = $data["title"];
        $maxEntries = $data["maxEntries"];
        $text = $this->generateLeaderboardText($title, $maxEntries);
        $entity = new FloatingTextEntity($position, $text, $world);
        $entity->spawnToAll();
        
        $this->floatingTexts[$name] = $entity;
    }
    
    private function generateLeaderboardText(string $title, int $maxEntries): string {
        $text = TF::BOLD . TF::YELLOW . $title . TF::RESET . "\n";
        $text .= TF::GRAY . "Updated: " . date("H:i:s") . "\n\n";
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
            $text .= TF::GRAY . "No players have used AFKZones yet.";
        }
        
        return $text;
    }
    
    private function updateLeaderboards(): void {
        foreach ($this->floatingTexts as $name => $entity) {
            $data = $this->leaderboard->get($name);
            
            if ($data === null) {
                continue;
            }
            
            $title = $data["title"];
            $maxEntries = $data["maxEntries"];
            $newText = $this->generateLeaderboardText($title, $maxEntries);
            $entity->setText($newText);
        }
    }
    
    public function getPlayerAFKTime(string $playerName): int {
        return $this->playerAFKTimes[$playerName] ?? 0;
    }
    
    public function getFormattedAFKTime(string $playerName): string {
        $time = $this->playerAFKTimes[$playerName] ?? 0;
        return $this->formatTime($time);
    }
}

class ScoreHudListener implements Listener {
    
    /** @var Main */
    private Main $plugin;
    
    /**
     * Constructor
     */
    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }
    
    public function onTagResolve(TagsResolveEvent $event): void {
        $tag = $event->getTag();
        
        if ($tag->getName() === "afkzone.time") {
            $player = $event->getPlayer();
            $time = $this->plugin->getFormattedAFKTime($player->getName());
            $tag->setValue($time);
        }
    }
}

class FloatingTextEntity {
    
    /** @var Vector3 */
    private Vector3 $position;
    
    /** @var string */
    private string $text;
    
    /** @var \pocketmine\world\World */
    private \pocketmine\world\World $world;
    
    /** @var int */
    private int $entityId;
    
    public function __construct(Vector3 $position, string $text, \pocketmine\world\World $world) {
        $this->position = $position;
        $this->text = $text;
        $this->world = $world;
        $this->entityId = \pocketmine\entity\Entity::nextRuntimeId();
        
        $this->spawnToAll();
    }
    
    public function spawnToAll(): void {
        foreach ($this->world->getPlayers() as $player) {
            $this->spawnTo($player);
        }
    }
    
    public function setText(string $text): void {
        $this->text = $text;
        $this->spawnToAll();
    }
}
