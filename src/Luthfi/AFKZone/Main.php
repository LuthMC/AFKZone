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
use pocketmine\world\World;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\math\Vector3;
use onebone\economyapi\EconomyAPI;
use cooldogepm\bedrockeconomy\api\BedrockEconomyAPI;

class Main extends PluginBase implements Listener {

    private $afkZone;
    private $playersInZone = [];
    private $economyPlugin;
    private $bedrockEconomyAPI;
    private $leaderboardPosition;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->afkZone = $this->getConfig()->get("afk-zone", []);
        $this->leaderboardPosition = $this->getConfig()->get("leaderboard-position", []);
        
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
                $this->getLogger()->error("BedrockEconomy plugin not found or not enabled! Defaulting to EconomyAPI.");
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
        }), 20);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
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
                $sender->sendMessage("Usage: /afkzone <setworld|setposition>");
                return true;
            }

            switch ($args[0]) {
                case "setworld":
                    $this->setAfkZoneWorld($sender);
                    break;
                case "setposition":
                    if (count($args) < 2) {
                        $sender->sendMessage("Usage: /afkzone setposition <1|2>");
                        return true;
                    }
                    $this->setAfkZonePosition($sender, $args[1]);
                    break;
                default:
                    $sender->sendMessage("Usage: /afkzone <setworld|setposition>");
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

            $this->setLeaderboardPosition($sender);
            return true;
        }

        return false;
    }

    private function setAfkZoneWorld(Player $player): void {
        $worldName = $player->getWorld()->getFolderName();
        $this->afkZone['world'] = $worldName;
        $this->getConfig()->set("afk-zone.world", $worldName);
        $this->getConfig()->save();
        $player->sendMessage("AFK zone world set to " . $worldName);
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
            $player->sendMessage("AFK zone position 1 set to X: $x, Y: $y, Z: $z");
        } elseif ($position === "2") {
            $this->afkZone['x2'] = $x;
            $this->afkZone['y2'] = $y;
            $this->afkZone['z2'] = $z;
            $this->getConfig()->set("afk-zone.x2", $x);
            $this->getConfig()->set("afk-zone.y2", $y);
            $this->getConfig()->set("afk-zone.z2", $z);
            $player->sendMessage("AFK zone position 2 set to X: $x, Y: $y, Z: $z");
        } else {
            $player->sendMessage("Invalid position. Use 1 or 2.");
            return;
        }

        $this->getConfig()->save();
    }

    private function setLeaderboardPosition(Player $player): void {
        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();
        $world = $player->getWorld()->getFolderName();

        $this->leaderboardPosition = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => $world
        ];

        $this->getConfig()->set("leaderboard-position", $this->leaderboardPosition);
        $this->getConfig()->save();

        $player->sendMessage("Top AFK leaderboard position set to X: $x, Y: $y, Z: $z in world $world");
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
        $amount = $this->getConfig()->get("reward-amount", 1000);
        if ($this->economyPlugin === "BedrockEconomy") {
            if ($this->bedrockEconomyAPI !== null) {
                $this->bedrockEconomyAPI->addToPlayerBalance($player->getName(), $amount, function (bool $success) use ($player, $amount): void {
                    if ($success) {
                        $player->sendMessage("You have received $amount for being in the AFK zone!");
                    } else {
                        $player->sendMessage("Failed to add money to your account.");
                    }
                });
            }
        } else {
            EconomyAPI::getInstance()->addMoney($player, $amount);
            $player->sendMessage("You have received $amount for being in the AFK zone!");
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
                $player->sendTitle("AFK §eZone", "§7Time: {$hours}h {$minutes}m {$seconds}s", 0, 20, 0);

                if ($timeInZone > 0 && $timeInZone % 60 === 0) {
                    $this->grantMoney($player);
                }
            }
        }
    }

    private function updateLeaderboard(): void {
        if (!empty($this->leaderboardPosition)) {
            arsort($this->playersInZone);
            $topPlayers = array_slice($this->playersInZone, 0, 10, true);
            $leaderboardText = "§eTop AFK Players:\n";

            $rank = 1;
            foreach ($topPlayers as $playerName => $time) {
                $leaderboardText .= "§6#{$rank} §f{$playerName} §7- §a" . gmdate("H:i:s", time() - $time) . "\n";
                $rank++;
            }

            $world = $this->getServer()->getWorldManager()->getWorldByName($this->leaderboardPosition['world']);
            if ($world instanceof World) {
                $position = new Vector3(
                    $this->leaderboardPosition['x'],
                    $this->leaderboardPosition['y'],
                    $this->leaderboardPosition['z']
                );
                $particle = new FloatingTextParticle("", $leaderboardText);
                $world->addParticle($position, $particle);
            }
        }
    }
}
