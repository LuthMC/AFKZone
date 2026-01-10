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
use pocketmine\item\VanillaItems;
use LuthMC\AFKZone\command\CommandHandler;
use LuthMC\AFKZone\ui\UIHandler;
use LuthMC\AFKZone\zone\ZoneManager;
use LuthMC\AFKZone\economy\EconomyHandler;
use LuthMC\AFKZone\listener\AFKListener;
use LuthMC\AFKZone\listener\ScoreHudListener;

class Main extends PluginBase implements Listener {

    private Config $afkZonesConfig;
    private Config $messagesConfig;
    private array $playerAFKTimes = [];
    private array $playerInZone = [];
    private array $lastTitleNotifyTime = [];
    private array $lastActionbarNotifyTime = [];
    private array $playerCurrentZone = [];

    private CommandHandler $commandHandler;
    private UIHandler $uiHandler;
    private ZoneManager $zoneManager;
    private EconomyHandler $economyHandler;
    private ?ScoreHudListener $scoreHudListener = null;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->saveDefaultConfig();
        $config = $this->getConfig();

        @mkdir($this->getDataFolder() . "data/");
        $this->afkZonesConfig = new Config($this->getDataFolder() . "data/afkzones.yml", Config::YAML);
        
        $this->messagesConfig = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
        $this->setupMessagesConfig();

        $this->zoneManager = new ZoneManager($this, $this->afkZonesConfig);
        $this->economyHandler = new EconomyHandler($this->getServer(), $config, $this->messagesConfig);
        $this->uiHandler = new UIHandler($this);
        $this->commandHandler = new CommandHandler($this);

        $this->getServer()->getPluginManager()->registerEvents(new AFKListener($this), $this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $playerName = $player->getName();
                $isInZone = $this->zoneManager->isPlayerInAFKZone($player);
                
                if ($isInZone) {
                    $this->playerAFKTimes[$playerName] = ($this->playerAFKTimes[$playerName] ?? 0) + 1;
                    $this->processTick($player);
                }
            }
        }), 20);

        if ($this->getServer()->getPluginManager()->getPlugin("ScoreHud") !== null) {
            $this->scoreHudListener = new ScoreHudListener($this);
            $this->getServer()->getPluginManager()->registerEvents($this->scoreHudListener, $this);
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

    private function processTick(Player $player): void {
        $playerName = $player->getName();
        $config = $this->getConfig();
        $rewardTimer = $config->get("rewards", [])["timer"] ?? 60;
        $currentZone = $this->zoneManager->getPlayerZoneName($player);

        if ($currentZone === null) {
            if (isset($this->playerAFKTimes[$playerName])) {
                unset($this->playerAFKTimes[$playerName]);
            }
            return;
        }

        $this->setPlayerCurrentZone($playerName, $currentZone);
        $time = $this->playerAFKTimes[$playerName] ?? 0;
        $timeInCycle = $time % $rewardTimer;
        $secondsUntilReward = max(1, $rewardTimer - $timeInCycle);

        $this->displayStayMessages($player, $secondsUntilReward);
        $this->updateScoreHud($player);

        if ($time > 0 && $time % $rewardTimer === 0) {
            $this->giveRewards($player, $currentZone);
            $this->playerAFKTimes[$playerName] = 0;
            unset($this->lastActionbarNotifyTime[$playerName]);
            unset($this->lastTitleNotifyTime[$playerName]);
        }
    }

    private function displayStayMessages(Player $player, int $secondsUntilReward): void {
        $playerName = $player->getName();
        $messages = $this->messagesConfig->get("messages", []);

        $titleRefresh = $messages["title"]["stay"]["refresh"] ?? 1;
        $actionbarRefresh = $messages["actionbar"]["stay"]["refresh"] ?? 1;

        if (isset($messages["actionbar"]["stay"]["enabled"]) && $messages["actionbar"]["stay"]["enabled"]) {
            $lastActionbarTime = $this->lastActionbarNotifyTime[$playerName] ?? 0;
            $timeSinceActionbar = ($this->playerAFKTimes[$playerName] ?? 0) - $lastActionbarTime;

            if ($timeSinceActionbar >= $actionbarRefresh) {
                $text = $messages["actionbar"]["stay"]["text"] ?? "Reward in: {countdown}s";
                $text = str_replace("{countdown}", (string)$secondsUntilReward, $text);
                $player->sendActionBarMessage($text);
                $this->lastActionbarNotifyTime[$playerName] = $this->playerAFKTimes[$playerName] ?? 0;
            }
        }

        if (isset($messages["title"]["stay"]["enabled"]) && $messages["title"]["stay"]["enabled"]) {
            $lastTitleTime = $this->lastTitleNotifyTime[$playerName] ?? 0;
            $timeSinceTitle = ($this->playerAFKTimes[$playerName] ?? 0) - $lastTitleTime;

            if ($timeSinceTitle >= $titleRefresh) {
                $title = $messages["title"]["stay"]["title"] ?? "AFKZone";
                $subtitle = $messages["title"]["stay"]["subtitle"] ?? "Reward in: {countdown}s";
                $subtitle = str_replace("{countdown}", (string)$secondsUntilReward, $subtitle);
                $player->sendTitle($title, $subtitle, 0, 25, 5);
                $this->lastTitleNotifyTime[$playerName] = $this->playerAFKTimes[$playerName] ?? 0;
            }
        }
    }

    private function giveRewards(Player $player, string $zoneName): void {
        $zoneRewards = $this->zoneManager->getZoneRewards($zoneName);
        $moneyAmount = $zoneRewards["money"] ?? 100;

        $this->economyHandler->giveMoney($player, $moneyAmount);
        $this->giveZoneItems($player, $zoneName);
    }

    private function updatePlayerAFKTimes(): void {
    }

    private function checkRewards(): void {
    }

    private function giveZoneItems(Player $player, string $zoneName): void {
        $zoneRewards = $this->zoneManager->getZoneRewards($zoneName);
        $items = $zoneRewards["items"] ?? [];
        
        if (!is_array($items)) {
            return;
        }

        $messages = $this->messagesConfig->get("messages", []);
        $itemRewardMessage = $messages["reward"]["item"] ?? "§aYou received §f{item}§a x§f{amount}§a!";

        foreach ($items as $itemData) {
            if (!is_array($itemData)) {
                continue;
            }

            $itemName = $itemData["item"] ?? "";
            $amount = $itemData["amount"] ?? 1;

            if (empty($itemName)) {
                continue;
            }

            $item = $this->getItemByName($itemName, $amount);

            if ($item !== null) {
                $player->getInventory()->addItem($item);
                $itemDisplayName = $item->getCustomName() !== "" ? $item->getCustomName() : $item->getName();
                $message = str_replace("{item}", $itemDisplayName, $itemRewardMessage);
                $message = str_replace("{amount}", (string)$amount, $message);
                $player->sendMessage($message);
            } else {
                $player->sendMessage("§cItem $itemName not found!");
            }
        }
    }

    private function getItemByName(string $itemName, int $amount): ?\pocketmine\item\Item {
        $methodName = strtoupper(str_replace([" ", "-"], "_", $itemName));
        
        try {
            $item = call_user_func([VanillaItems::class, $methodName]);
            if ($item instanceof \pocketmine\item\Item) {
                $item->setCount($amount);
                return $item;
            }
        } catch (\Throwable $e) {
            return null;
        }
        
        return null;
    }

    public function isValidItemName(string $itemName): bool {
        $methodName = strtoupper(str_replace(" ", "_", $itemName));
        
        try {
            $item = call_user_func([VanillaItems::class, $methodName]);
            return $item instanceof \pocketmine\item\Item;
        } catch (\Throwable $e) {
            return false;
        }
        
        return false;
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

    public function getPlayerCurrentZone(string $playerName): ?string {
        return $this->playerCurrentZone[$playerName] ?? null;
    }

    public function setPlayerCurrentZone(string $playerName, ?string $zoneName): void {
        if ($zoneName === null) {
            unset($this->playerCurrentZone[$playerName]);
        } else {
            $this->playerCurrentZone[$playerName] = $zoneName;
        }
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
        $this->updateScoreHud($player);
    }

    public function notifyLeave(Player $player): void {
        $messages = $this->messagesConfig->get("messages", []);
        
        if (isset($messages["title"]["leave"]["enabled"]) && $messages["title"]["leave"]["enabled"]) {
            $title = $messages["title"]["leave"]["title"] ?? "AFKZone";
            $subtitle = $messages["title"]["leave"]["subtitle"] ?? "Left AFKZone";
            $player->sendTitle($title, $subtitle);
        }

        if (isset($messages["actionbar"]["enabled"]) && $messages["actionbar"]["enabled"]) {
            $text = $messages["actionbar"]["leave"] ?? "Left AFKZone";
            $text = str_replace("{player}", $player->getName(), $text);
            $player->sendActionBarMessage($text);
        }

        $this->playSound($player, "leave");
        $this->updateScoreHud($player, "Not Found");
    }

    private function updateScoreHud(Player $player, ?string $value = null): void {
        if ($this->scoreHudListener === null) {
            return;
        }

        if ($value === null) {
            $config = $this->getConfig();
            $rewardTimer = $config->get("rewards", [])["timer"] ?? 60;
            $time = $this->getPlayerAFKTime($player->getName());
            $timeInCycle = $time % $rewardTimer;
            $secondsUntilReward = max(1, $rewardTimer - $timeInCycle);
            $value = $secondsUntilReward . "s";
        }

        $this->scoreHudListener->updatePlayerTag($player, $value);
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

    public function resetPlayerAFKTime(string $playerName): void {
        $this->playerAFKTimes[$playerName] = 0;
        unset($this->lastActionbarNotifyTime[$playerName]);
        unset($this->lastTitleNotifyTime[$playerName]);
    }

    public function getFormattedAFKTime(string $playerName): string {
        $timeTicks = $this->playerAFKTimes[$playerName] ?? 0;
        $timeSeconds = intdiv($timeTicks, 20);
        $hours = intdiv($timeSeconds, 3600);
        $minutes = intdiv($timeSeconds % 3600, 60);
        $seconds = $timeSeconds % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }
}
