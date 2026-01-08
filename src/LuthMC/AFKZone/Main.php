<?php

declare(strict_types=1);

namespace LuthMC\AFKZone;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\world\sound\PopSound;
use pocketmine\world\sound\ClickSound;
use LuthMC\AFKZone\command\CommandHandler;
use LuthMC\AFKZone\ui\UIHandler;
use LuthMC\AFKZone\zone\ZoneManager;
use LuthMC\AFKZone\economy\EconomyHandler;
use LuthMC\AFKZone\entity\FloatingTextHandler;
use LuthMC\AFKZone\listener\AFKListener;
use LuthMC\AFKZone\listener\ScoreHudListener;

class Main extends PluginBase implements Listener {

    private Config $afkZonesConfig;
    private Config $leaderboardConfig;
    private Config $messagesConfig;
    private array $playerAFKTimes = [];
    private array $lastStayNotifyTime = [];

    private CommandHandler $commandHandler;
    private UIHandler $uiHandler;
    private ZoneManager $zoneManager;
    private EconomyHandler $economyHandler;
    private FloatingTextHandler $floatingTextHandler;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->saveDefaultConfig();
        $config = $this->getConfig();

        @mkdir($this->getDataFolder() . "data/");
        $this->afkZonesConfig = new Config($this->getDataFolder() . "data/afkzones.yml", Config::YAML);
        $this->leaderboardConfig = new Config($this->getDataFolder() . "data/leaderboard.yml", Config::YAML);
        
        $this->messagesConfig = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
        $this->setupMessagesConfig();

        $this->zoneManager = new ZoneManager($this, $this->afkZonesConfig);
        $this->economyHandler = new EconomyHandler($this->getServer(), $config, $this->messagesConfig);
        $this->uiHandler = new UIHandler($this);
        $this->floatingTextHandler = new FloatingTextHandler($this, $this->leaderboardConfig);
        $this->commandHandler = new CommandHandler($this);

        $this->getServer()->getPluginManager()->registerEvents(new AFKListener($this), $this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updatePlayerAFKTimes();
            $this->checkRewards();
        }), 20);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->floatingTextHandler->updateLeaderboards();
        }), 20 * 60);

        $this->floatingTextHandler->loadFloatingTexts();

        if ($this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null) {
            $this->getServer()->getPluginManager()->registerEvents(new ScoreHudListener($this), $this);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        return $this->commandHandler->handle($sender, $command, $label, $args);
    }

    private function setupMessagesConfig(): void {
        $defaultMessages = [
            "messages" => [
                "actionbar" => [
                    "enabled" => true,
                    "enter" => "§eEntered AFKZone",
                    "leave" => "§eLeft AFKZone",
                    "stay" => [
                        "enabled" => true,
                        "text" => "§6Reward in: §f{countdown}s",
                        "refresh" => 1
                    ]
                ],
                "title" => [
                    "enter" => [
                        "enabled" => true,
                        "title" => "§6AFKZone",
                        "subtitle" => "§eYou entered an afk zone"
                    ],
                    "leave" => [
                        "enabled" => true,
                        "title" => "§6AFKZone",
                        "subtitle" => "§eYou left an afk zone"
                    ],
                    "stay" => [
                        "enabled" => true,
                        "title" => "§6AFKZone",
                        "subtitle" => "§eReward in: §f{countdown}s",
                        "refresh" => 1
                    ]
                ],
                "reward" => [
                    "money" => "§aYou received §f\${amount}§a for staying in the AFKZone!",
                    "item" => "§aYou received §f{item}§a x§f{amount}§a!"
                ]
            ]
        ];

        if (empty($this->messagesConfig->getAll())) {
            foreach ($defaultMessages as $key => $value) {
                $this->messagesConfig->set($key, $value);
            }
            $this->messagesConfig->save();
        }
    }

    private function updatePlayerAFKTimes(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();

            if ($this->zoneManager->isPlayerInAFKZone($player)) {
                $this->playerAFKTimes[$playerName] = ($this->playerAFKTimes[$playerName] ?? 0) + 1;
            }
        }
    }

    private function checkRewards(): void {
        $rewardTimer = $this->getConfig()->get("rewards", [])["timer"] ?? 60;
        $moneyAmount = $this->getConfig()->get("rewards", [])["money"] ?? 100;

        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            $playerName = $player->getName();

            if ($this->zoneManager->isPlayerInAFKZone($player)) {
                $time = $this->playerAFKTimes[$playerName] ?? 0;
                
                $secondsUntilReward = ($rewardTimer - ($time % $rewardTimer));
                $this->notifyStay($player, $secondsUntilReward);

                if ($time > 0 && $time % $rewardTimer === 0) {
                    $this->economyHandler->giveMoney($player, $moneyAmount);
                    $this->economyHandler->giveItems($player);
                }
            }
        }
    }

    public function getCommandHandler(): CommandHandler {
        return $this->commandHandler;
    }

    public function getUIHandler(): UIHandler {
        return $this->uiHandler;
    }

    public function getZoneManager(): ZoneManager {
        return $this->zoneManager;
    }

    public function getEconomyHandler(): EconomyHandler {
        return $this->economyHandler;
    }

    public function getFloatingTextHandler(): FloatingTextHandler {
        return $this->floatingTextHandler;
    }

    public function getPlayerAFKTime(string $playerName): int {
        return $this->playerAFKTimes[$playerName] ?? 0;
    }

    public function notifyEnter(Player $player): void {
        $messages = $this->messagesConfig->get("messages", []);
        
        if (isset($messages["title"]["enter"]["enabled"]) && $messages["title"]["enter"]["enabled"]) {
            $title = $messages["title"]["enter"]["title"] ?? "AFKZone";
            $subtitle = $messages["title"]["enter"]["subtitle"] ?? "Entered AFK Zone";
            $player->sendTitle($title, $subtitle);
        }

        if (isset($messages["actionbar"]["enabled"]) && $messages["actionbar"]["enabled"]) {
            $text = $messages["actionbar"]["enter"] ?? "Entered AFKZone";
            $text = str_replace("{player}", $player->getName(), $text);
            $player->sendActionBarMessage($text);
        }

        $this->playSound($player, "enter");
    }

    public function notifyLeave(Player $player): void {
        $messages = $this->messagesConfig->get("messages", []);
        
        if (isset($messages["title"]["leave"]["enabled"]) && $messages["title"]["leave"]["enabled"]) {
            $title = $messages["title"]["leave"]["title"] ?? "AFKZone";
            $subtitle = $messages["title"]["leave"]["subtitle"] ?? "Left AFK Zone";
            $player->sendTitle($title, $subtitle);
        }

        if (isset($messages["actionbar"]["enabled"]) && $messages["actionbar"]["enabled"]) {
            $text = $messages["actionbar"]["leave"] ?? "Left AFKZone";
            $text = str_replace("{player}", $player->getName(), $text);
            $player->sendActionBarMessage($text);
        }

        $this->playSound($player, "leave");
    }

    public function notifyStay(Player $player, int $secondsUntilReward): void {
        $messages = $this->messagesConfig->get("messages", []);
        $playerName = $player->getName();
        $currentTime = time();
        $lastNotifyTime = $this->lastStayNotifyTime[$playerName] ?? 0;

        $actionbarRefresh = 1;
        $titleRefresh = 1;

        if (isset($messages["actionbar"]["stay"]["refresh"])) {
            $actionbarRefresh = $messages["actionbar"]["stay"]["refresh"];
        }
        if (isset($messages["title"]["stay"]["refresh"])) {
            $titleRefresh = $messages["title"]["stay"]["refresh"];
        }

        if (($currentTime - $lastNotifyTime) >= min($actionbarRefresh, $titleRefresh)) {
            $this->lastStayNotifyTime[$playerName] = $currentTime;

            if (isset($messages["actionbar"]["stay"]["enabled"]) && $messages["actionbar"]["stay"]["enabled"]) {
                if (($currentTime - $lastNotifyTime) >= $actionbarRefresh) {
                    $text = $messages["actionbar"]["stay"]["text"] ?? "Reward in: {countdown}s";
                    $text = str_replace("{countdown}", (string)$secondsUntilReward, $text);
                    $player->sendActionBarMessage($text);
                }
            }

            if (isset($messages["title"]["stay"]["enabled"]) && $messages["title"]["stay"]["enabled"]) {
                if (($currentTime - $lastNotifyTime) >= $titleRefresh) {
                    $title = $messages["title"]["stay"]["title"] ?? "AFKZone";
                    $subtitle = $messages["title"]["stay"]["subtitle"] ?? "Reward in: {countdown}s";
                    $subtitle = str_replace("{countdown}", (string)$secondsUntilReward, $subtitle);
                    $player->sendTitle($title, $subtitle);
                }
            }
        }
    }

    private function playSound(Player $player, string $type): void {
        $soundMap = [
            "enter" => ClickSound::class,
            "leave" => PopSound::class,
        ];

        $soundClass = $soundMap[$type] ?? PopSound::class;
        if (class_exists($soundClass)) {
            $sound = new $soundClass();
            $player->getWorld()->addSound($player->getPosition(), $sound);
        }
    }

    public function getPlayerAFKTimes(): array {
        return $this->playerAFKTimes;
    }

    public function addPlayerAFKTime(string $playerName, int $time): void {
        $this->playerAFKTimes[$playerName] = $time;
    }

    public function getFormattedAFKTime(string $playerName): string {
        $time = $this->playerAFKTimes[$playerName] ?? 0;
        $hours = intdiv($time, 3600);
        $minutes = intdiv($time % 3600, 60);
        $seconds = $time % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }
}
