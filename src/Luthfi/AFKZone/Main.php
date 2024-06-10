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
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\ScoreHud;
use Ifera\ScoreHud\lib\scoreboard\ScoreTag;
use onebone\economyapi\EconomyAPI;
use cooldogepm\bedrockeconomy\api\BedrockEconomyAPI;

class Main extends PluginBase implements Listener {

    private $afkZone;
    private $playersInZone = [];
    private $afkTimes = [];
    private $economyPlugin;
    private $bedrockEconomyAPI;
    private $topAfkPosition;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->afkZone = $this->getConfig()->get("afk-zone", []);
        $this->topAfkPosition = $this->getConfig()->get("top-afk-position", null);

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
            $this->updateTopAfkLeaderboard();
        }), 600);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        new ScoreHudListener($this);
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
        }

        if ($command->getName() === "settopafk") {
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

        if ($command->getName() === "topafk") {
            if (!$sender->hasPermission("afkzone.topafk")) {
                $sender->sendMessage("You do not have permission to use this command.");
                return true;
            }

            $this->sendTopAfkTimes($sender);
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

    private function setTopAfkPosition(Player $player): void {
        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();
        $world = $player->getWorld()->getFolderName();

        $this->topAfkPosition = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'world' => $world
        ];

        $this->getConfig()->set("top-afk-position", $this->topAfkPosition);
        $this->getConfig()->save();

        $player->sendMessage("Top AFK leaderboard position set to X: $x, Y: $y, Z: $z in world $world");
    }

    public function checkAfkZone(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $name = $player->getName();

            if ($this->isInAfkZone($player)) {
                if (!isset($this->playersInZone[$name])) {
                    $this->playersInZone[$name] = time();
                    $this->afkTimes[$name] = $this->afkTimes[$name] ?? 0;
                }
            } else {
                if (isset($this->playersInZone[$name])) {
                    $timeInZone = time() - $this->playersInZone[$name];
                    $this->afkTimes[$name] += $timeInZone;
                    unset($this->playersInZone[$name]);
                }
            }
        }
    }

    private function isInAfkZone(Player $player): bool {
        if (empty($this->afkZone['world']) || !isset($this->afkZone['x1']) || !isset($this->afkZone['x2'])) {
            return false;
        }

        $world = $player->getWorld()->getFolderName();
        if ($world !== $this->afkZone['world']) {
            return false;
        }

        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();

        return (
            $x >= min($this->afkZone['x1'], $this->afkZone['x2']) &&
            $x <= max($this->afkZone['x1'], $this->afkZone['x2']) &&
            $y >= min($this->afkZone['y1'], $this->afkZone['y2']) &&
            $y <= max($this->afkZone['y1'], $this->afkZone['y2']) &&
            $z >= min($this->afkZone['z1'], $this->afkZone['z2']) &&
            $z <= max($this->afkZone['z1'], $this->afkZone['z2'])
        );
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

                $afkTime = "{$hours}h {$minutes}m {$seconds}s";
                ScoreHud::getInstance()->getTagManager()->resetTag($player, new ScoreTag("afkzone.time", $afkTime));
            }
        }
    }

    private function grantMoney(Player $player): void {
        $amount = $this->getConfig()->get("reward-amount", 10);

        if ($this->economyPlugin === "BedrockEconomy") {
            if ($this->bedrockEconomyAPI !== null) {
                $this->bedrockEconomyAPI->addToPlayerBalance($player->getName(), $amount, function (bool $success) use ($player, $amount): void {
                    if ($success) {
                        $player->sendMessage("You've been awarded $amount for staying in the AFK zone!");
                    } else {
                        $player->sendMessage("Failed to award $amount.");
                    }
                });
            }
        } else {
            $economyAPI = EconomyAPI::getInstance();
            if ($economyAPI !== null) {
                $economyAPI->addMoney($player, $amount);
                $player->sendMessage("You've been awarded $amount for staying in the AFK zone!");
            }
        }
    }

    private function updateTopAfkLeaderboard(): void {
        if (!$this->topAfkPosition) {
            return;
        }

        $worldName = $this->topAfkPosition['world'];
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);

        if (!$world instanceof World) {
            return;
        }

        $x = $this->topAfkPosition['x'];
        $y = $this->topAfkPosition['y'];
        $z = $this->topAfkPosition['z'];

        $leaderboard = $this->getTopAfkTimes();
        $lines = ["§eTop AFK Times:"];
        foreach ($leaderboard as $entry) {
            $lines[] = "{$entry['name']}: {$entry['time']} seconds";
        }

        $text = implode("\n", $lines);
        $position = new Position($x, $y, $z, $world);
        $particle = new FloatingTextParticle("", $text);

        $world->addParticle($position, $particle, $world->getPlayers());
    }

    private function getTopAfkTimes(): array {
        arsort($this->afkTimes);
        $topTimes = array_slice($this->afkTimes, 0, 10, true);
        $leaderboard = [];
        foreach ($topTimes as $name => $time) {
            $leaderboard[] = ['name' => $name, 'time' => $time];
        }
        return $leaderboard;
    }
}
