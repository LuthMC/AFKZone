<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\economy;

use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\utils\Config;
use pocketmine\Server;
use pocketmine\item\VanillaItems;

class EconomyHandler {

    private string $economyPlugin = "";
    private mixed $bedrockEconomyAPI = null;

    public function __construct(
        private Server $server,
        private Config $config,
        private Config $messagesConfig
    ) {
        $this->setupEconomy();
    }

    private function setupEconomy(): void {
        if ($this->server->getPluginManager()->getPlugin("BedrockEconomy") !== null) {
            $this->economyPlugin = "BedrockEconomy";
            return;
        }

        if ($this->server->getPluginManager()->getPlugin("EconomyAPI") !== null) {
            $this->economyPlugin = "EconomyAPI";
            return;
        }
    }

    public function giveMoney(Player $player, int $amount): void {
        if ($amount <= 0) {
            return;
        }

        $messages = $this->messagesConfig->get("messages", []);
        $rewardMessage = $messages["reward"]["money"] ?? "§aYou received §f{amount}§a for staying in the AFKZone!";
        $rewardMessage = str_replace("{amount}", (string)$amount, $rewardMessage);

        if ($this->economyPlugin === "BedrockEconomy") {
            $bedrockEconomy = $this->server->getPluginManager()->getPlugin("BedrockEconomy");
            if ($bedrockEconomy !== null && method_exists($bedrockEconomy, "getAPI")) {
                $this->bedrockEconomyAPI = $bedrockEconomy->getAPI();
                $this->bedrockEconomyAPI->addToPlayerBalance($player->getName(), $amount, function (bool $success) use ($player, $rewardMessage): void {
                    if ($success) {
                        $player->sendMessage($rewardMessage);
                    } else {
                        $player->sendMessage(TF::RED . "Uh oh! Something went wrong while adding money.");
                    }
                });
            }
        } elseif ($this->economyPlugin === "EconomyAPI") {
            $economyAPI = $this->server->getPluginManager()->getPlugin("EconomyAPI");
            if ($economyAPI !== null && method_exists($economyAPI, "addMoney")) {
                $economyAPI->addMoney($player, $amount);
                $player->sendMessage($rewardMessage);
            }
        }
    }

    public function giveItems(Player $player): void {
        $rewardItems = $this->config->get("reward-items", []);
        
        if (!isset($rewardItems["enabled"]) || !$rewardItems["enabled"]) {
            return;
        }

        $messages = $this->messagesConfig->get("messages", []);
        $itemRewardMessage = $messages["reward"]["item"] ?? "§aYou received §f{item}§a x§f{amount}§a!";

        $amount = $rewardItems["amount"] ?? 1;
        $items = $rewardItems["items"] ?? [];
        foreach ($items as $itemName) {
            $item = VanillaItems::{$itemName}();
            if ($item !== null) {
                $item->setCount($amount);
                $player->getInventory()->addItem($item);
                $itemDisplayName = $item->getCustomName() !== "" ? $item->getCustomName() : $item->getName();
                $message = str_replace("{item}", $itemDisplayName, $itemRewardMessage);
                $message = str_replace("{amount}", (string)$amount, $message);
                $player->sendMessage($message);
            }
        }
    }

    public function isEnabled(): bool {
        return $this->economyPlugin !== "";
    }

    public function getPluginName(): string {
        return $this->economyPlugin;
    }
}

