<?php

declare(strict_types=1);

namespace Luthfi\AFKZone;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use Ifera\ScoreHud\event\PlayerTagUpdateEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use Ifera\ScoreHud\ScoreHud;
use jojoe77777\FormAPI\SimpleForm;
use onebone\economyapi\EconomyAPI;
use cooldogepm\bedrockeconomy\api\BedrockEconomyAPI;

class Main extends PluginBase implements Listener {

    private $afkZone;
    private $playersInZone = [];
    private $economyPlugin;
    private $bedrockEconomyAPI;
    private $leaderboardParticles = [];
    private $scoreHud;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->afkZone = $this->getConfig()->get("afk-zone", []);
        $this->scoreHud = $this->getServer()->getPluginManager()->getPlugin("ScoreHud");

        $economy = $this->getConfig()->get("economy-plugin", "EconomyAPI");
        if ($economy === "BedrockEconomy") {
            $bedrockEconomy = $this->getServer()->getPluginManager()->getPlugin("BedrockEconomy");
            if ($bedrockEconomy !== null && $bedrockEconomy->isEnabled()) {
                $this->economyPlugin = "BedrockEconomy";
                if (class_exists(BedrockEconomyAPI::class)) {
                    $this->bedrockEconomyAPI = BedrockEconomyAPI::getInstance();
                } else {
                    $this->getLogger()->error("BedrockEconomyAPI class not found!");
                    $this->economyPlugin = "EconomyAPI";
                }
            } else {
                $this->getLogger()->error("BedrockEconomy not found or not enabled! Defaulting to EconomyAPI.");
                $this->economyPlugin = "EconomyAPI";
            }
        } else {
            $this->economyPlugin = "EconomyAPI";
        }

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->checkAfkZone();
        }), 20);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updatePlayerTimes();
            $this->updateLeaderboard();
        }), 20 * 60);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
    if ($command->getName() === "afkzone") {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command can only be used in-game.");
            return true;
        }

        if (!$sender->hasPermission("afkzone.command")) {
            $sender->sendMessage("You do not have permission to use this command.");
            return true;
        }

        if (count($args) < 1) {
            $sender->sendMessage("Usage: /afkzone <ui|setworld|setposition>");
            return true;
        }

        switch ($args[0]) {
            case "ui":
                $this->showAfkZoneForm($sender);
                break;
            case "setworld":
                $this->setAfkZoneWorld($sender);
                break;
            case "setposition":
                if (!isset($this->afkZone['world'])) {
                    $sender->sendMessage("You must set the world before setting positions.");
                    return true;
                }
                if (count($args) < 2) {
                    $sender->sendMessage("Usage: /afkzone setposition <1|2>");
                    return true;
                }
                $this->setAfkZonePosition($sender, $args[1]);
                break;
            default:
                $sender->sendMessage("Usage: /afkzone <ui|setworld|setposition>");
                return true;
        }
        return true;
    } elseif ($command->getName() === "settopafk") {
        if (!$sender instanceof Player) {
            $sender->sendMessage("This command can only be used in-game.");
            return true;
        }

        if (!$sender->hasPermission("afkzone.settopafk")) {
            $sender->sendMessage("You do not have permission to use this command.");
            return true;
        }

        $this->setTopAfkPosition($sender);
        return true;
    }

    return false;
 }
    
    private function setAfkZoneWorld(Player $player): void {
        $worldName = $player->getWorld()->getFolderName();
        $this->afkZone['world'] = $worldName;
        $this->getConfig()->set("afk-zone.world", $worldName);
        $this->getConfig()->save();
        $player->sendMessage("AFKZone world set to " . $worldName);
    }

    private function setAfkZonePosition(Player $player, string $position): void {
        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();

        if ($position === "1") {
            $this->afkZone['x1'] = $x;
            $this->afkZone['y1'] = $y;
            $this->afkZone['z1'] = $z;
            $this->getConfig()->set("afk-zone.x1", $x);
            $this->getConfig()->set("afk-zone.y1", $y);
            $this->getConfig()->set("afk-zone.z1", $z);
            $player->sendMessage("AFKZone position 1 set to X: $x, Y: $y, Z: $z");
        } elseif ($position === "2") {
            $this->afkZone['x2'] = $x;
            $this->afkZone['y2'] = $y;
            $this->afkZone['z2'] = $z;
            $this->getConfig()->set("afk-zone.x2", $x);
            $this->getConfig()->set("afk-zone.y2", $y);
            $this->getConfig()->set("afk-zone.z2", $z);
            $player->sendMessage("AFKZone position 2 set to X: $x, Y: $y, Z: $z");
        } else {
            $player->sendMessage("Invalid position. Use 1 or 2.");
            return;
        }

        $this->getConfig()->save();
    }

    private function setTopAfkPosition(Player $player): void {
        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();
        $world = $player->getWorld()->getFolderName();

        $this->getConfig()->set("leaderboard.position.x", $x);
        $this->getConfig()->set("leaderboard.position.y", $y);
        $this->getConfig()->set("leaderboard.position.z", $z);
        $this->getConfig()->set("leaderboard.position.world", $world);
        $this->getConfig()->save();

        $player->sendMessage("Leaderboard position set to X: $x, Y: $y, Z: $z in world: $world");
    }

    public function checkAfkZone(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($this->isInAfkZone($player)) {
                if (!isset($this->playersInZone[$player->getName()])) {
                    $this->playersInZone[$player->getName()] = time();
                }
            } else {
                if (isset($this->playersInZone[$player->getName()])) {
                    unset($this->playersInZone[$player->getName()]);
                    $player->sendTitle("", "");

                    if ($this->scoreHud instanceof ScoreHud && $this->scoreHud->isEnabled()) {
                        $tag = new ScoreTag("afkzone.time", "");
                        (new PlayerTagUpdateEvent($player, $tag))->call();
                    }
                }
            }
        }
    }

    private function isInAfkZone(Player $player): bool {
        $pos = $player->getPosition();
        $worldName = $player->getWorld()->getFolderName();
        if (!isset($this->afkZone['world']) || $this->afkZone['world'] !== $worldName) {
            return false;
        }
        return (
            $pos->getX() >= min($this->afkZone['x1'], $this->afkZone['x2']) &&
            $pos->getX() <= max($this->afkZone['x1'], $this->afkZone['x2']) &&
            $pos->getY() >= min($this->afkZone['y1'], $this->afkZone['y2']) &&
            $pos->getY() <= max($this->afkZone['y1'], $this->afkZone['y2']) &&
            $pos->getZ() >= min($this->afkZone['z1'], $this->afkZone['z2']) &&
            $pos->getZ() <= max($this->afkZone['z1'], $this->afkZone['z2'])
        );
    }

    private function grantMoney(Player $player): void {
        $this->grantRewards($player);
    }
    
    private function grantRewards(Player $player): void {
        $rewards = $this->getConfig()->get("rewards", []);
        foreach ($rewards as $reward) {
            switch ($reward["type"]) {
                case "money":
                    $amount = $reward["amount"];
                    if ($this->economyPlugin === "BedrockEconomy") {
                        if ($this->bedrockEconomyAPI !== null) {
                            $this->bedrockEconomyAPI->addToPlayerBalance($player->getName(), $amount, function (bool $success) use ($player, $amount): void {
                                if ($success) {
                                    $player->sendMessage("You have received $amount for being in the AFKZone!");
                                    $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\PopSound());
                                } else {
                                    $player->sendMessage("Failed to add money to your account.");
                                }
                            });
                        }
                    } else {
                        EconomyAPI::getInstance()->addMoney($player, $amount);
                        $player->sendMessage("You have received $amount for being in the AFKZone!");
                        $player->getWorld()->addSound($player->getPosition(), new \pocketmine\world\sound\PopSound());
                    }
                    break;
                case "item":
                    $itemId = $reward["item"];
                    $amount = $reward["amount"];
                    $item = \pocketmine\item\ItemFactory::getInstance()->get($itemId, 0, $amount);
                    $player->getInventory()->addItem($item);
                    $player->sendMessage("You have received $amount x $itemId for being in the AFKZone!");
                    break;
                case "command":
                    $command = str_replace("{player}", $player->getName(), $reward["command"]);
                    $this->getServer()->dispatchCommand(new \pocketmine\command\ConsoleCommandSender(), $command);
                    $player->sendMessage("You have received a command reward for being in the AFKZone!");
                    break;
            }
        }
    }
    
    private function updatePlayerTimes(): void {
        foreach ($this->playersInZone as $name => $enterTime) {
            $player = $this->getServer()->getPlayerExact($name);
            if ($player instanceof Player) {
                $timeInZone = time() - $enterTime;
                $hours = floor($timeInZone / 3600);
                $minutes = floor(($timeInZone % 3600) / 60);
                $seconds = $timeInZone % 60;

                if ($this->scoreHud instanceof ScoreHud && $this->scoreHud->isEnabled()) {
                    $tag = new ScoreTag("afkzone.time", "Time§7:§r {$hours}h, {$minutes}m, {$seconds}s");
                    (new PlayerTagUpdateEvent($player, $tag))->call();
                }
                
                $player->sendTitle("§bAFK§eZone", "§7Time: {$hours}h {$minutes}m {$seconds}s", 0, 20, 0);

                if ($timeInZone > 0 && $timeInZone % 60 === 0) {
                    $this->grantMoney($player);
                }
            }
        }
    }

   private function showAfkZoneForm(Player $player): void {
    $form = new SimpleForm(function (Player $player, ?int $data) {
        if ($data === null) {
            return;
        }

        switch ($data) {
            case 0:
                $this->setAfkZoneWorld($player);
                break;
            case 1:
                if (!isset($this->afkZone['world'])) {
                    $player->sendMessage("You must set the world before setting positions.");
                    return;
                }
                $this->setAfkZonePosition($player, "1");
                break;
            case 2:
                if (!isset($this->afkZone['world'])) {
                    $player->sendMessage("You must set the world before setting positions.");
                    return;
                }
                $this->setAfkZonePosition($player, "2");
                break;
            case 3:
                $this->setTopAfkPosition($player);
                break;
            case 4:
                $this->unsetAfkLeaderboardPosition($player);
                break;
        }
    });

    $form->setTitle("§bAFKZone §fSettings");
    $form->addButton("Set AFKZone World");
    $form->addButton("Set AFKZone Position 1");
    $form->addButton("Set AFKZone Position 2");
    $form->addButton("Set Leaderboard Position");
    $form->addButton("Unset Leaderboard Position");
    $player->sendForm($form);
 }

    private function unsetAfkLeaderboardPosition(Player $player): void {
    $config = $this->getConfig();
    if ($config->exists("leaderboard.position")) {
        $config->remove("leaderboard.position.world");
        $config->remove("leaderboard.position.x");
        $config->remove("leaderboard.position.y");
        $config->remove("leaderboard.position.z");
        $config->save();
        $player->sendMessage("Leaderboard position has been unset.");
    } else {
        $player->sendMessage("Leaderboard position is not set.");
    }
 }
    
    private function updateLeaderboard(): void {
        arsort($this->playersInZone);
        $topPlayers = array_slice($this->playersInZone, 0, 5, true);

        foreach ($this->leaderboardParticles as $particle) {
            $particle->getWorld()->addParticle($particle->getPosition(), new FloatingTextParticle("", ""));
        }
        $this->leaderboardParticles = [];

        $config = $this->getConfig();
        if (!$config->exists("leaderboard.position.world")) {
            $this->getLogger()->warning("Leaderboard position is not set.");
            return;
        }

        $worldName = $config->get("leaderboard.position.world");
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        if ($world === null) {
            $this->getLogger()->warning("World '$worldName' not found.");
            return;
        }

        $x = $config->get("leaderboard.position.x");
        $y = $config->get("leaderboard.position.y");
        $z = $config->get("leaderboard.position.z");
        $basePosition = new Vector3($x, $y, $z);

        $index = 0;
        foreach ($topPlayers as $name => $time) {
            $timeText = gmdate("H:i:s", $time);
            $text = "§e{$name}: §7{$timeText}";

            $position = $basePosition->add(0, $index * 1.5, 0);
            $particle = new FloatingTextParticle($text);
            $world->addParticle($position, $particle);

            $this->leaderboardParticles[] = $particle;
            $index++;
        }
    }
}
