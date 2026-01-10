<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\ui;

use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use LuthMC\AFKZone\libs\Vasync\LSFormAPI\SimpleForm;
use LuthMC\AFKZone\libs\Vasync\LSFormAPI\CustomForm;
use LuthMC\AFKZone\Main;

class UIHandler {

    public function __construct(private Main $plugin) {}

    public function openMainUI(Player $player): void {
        $form = new SimpleForm("AFKZone Settings", "Select an option below:", function (Player $player, ?int $data) {
            if ($data === null) {
                return;
            }

            switch ($data) {
                case 0:
                    if ($player->hasPermission("afkzone.cmd")) {
                        $this->openPositionUI($player);
                    } else {
                        $player->sendMessage(TF::RED . "You don't have permission.");
                    }
                    break;

                case 1:
                    $this->openZoneListUI($player);
                    break;
            }
        });

        $form->addButton("Setup AFKZone");
        $form->addButton("Manage AFKZone");

        $player->sendForm($form);
    }

    public function openPositionUI(Player $player): void {
        $form = new SimpleForm("Position Settings", "Choose an action:", function (Player $player, ?int $data) {
            if ($data === null) {
                $this->openMainUI($player);
                return;
            }

            switch ($data) {
                case 0:
                    $this->plugin->getCommandHandler()->setPosition($player, 1);
                    break;

                case 1:
                    $this->plugin->getCommandHandler()->setPosition($player, 2);
                    break;

                case 2:
                    $this->openCreateZoneUI($player);
                    break;

                case 3:
                    $this->openMainUI($player);
                    break;
            }
        });

        $form->addButton("Set Position 1");
        $form->addButton("Set Position 2");
        $form->addButton("Create Zone");
        $form->addButton("Back");

        $player->sendForm($form);
    }

    public function openCreateZoneUI(Player $player): void {
        $zoneManager = $this->plugin->getZoneManager();

        if (!$zoneManager->hasPlayerPosition($player->getName(), 1) || !$zoneManager->hasPlayerPosition($player->getName(), 2)) {
            $player->sendMessage(TF::RED . "Please set both positions first!");
            $this->openPositionUI($player);
            return;
        }

        $form = new CustomForm("Create AFKZone", function (Player $player, ?array $data) {
            if ($data === null) {
                $this->openPositionUI($player);
                return;
            }

            $zoneName = $data[0] ?? "";

            if (empty($zoneName)) {
                $player->sendMessage(TF::RED . "Zone name cannot be empty!");
                return;
            }

            $zoneManager = $this->plugin->getZoneManager();

            if ($zoneManager->zoneExists($zoneName)) {
                $player->sendMessage(TF::RED . "Zone '$zoneName' already exists!");
                return;
            }

            $zoneManager->createZone($zoneName, $player->getName());
            $player->sendMessage(TF::GREEN . "AFKZone '$zoneName' created!");
            $this->openMainUI($player);
        });

        $form->addInput("Zone Name", "afkzone", "");
        $player->sendForm($form);
    }

    public function openZoneListUI(Player $player): void {
        $zoneManager = $this->plugin->getZoneManager();
        $zones = $zoneManager->getAllZones();

        if (empty($zones)) {
            $player->sendMessage(TF::YELLOW . "There are no AFKZone created yet.");
            $this->openMainUI($player);
            return;
        }

        $form = new SimpleForm("AFKZone", "Select a zone to view details" . ($player->hasPermission("afkzone.cmd") ? " or manage" : "") . ":", function (Player $player, ?int $data) use ($zones) {
            if ($data === null) {
                $this->openMainUI($player);
                return;
            }

            $zoneNames = array_keys($zones);
            $selectedZone = $zoneNames[$data] ?? null;

            if ($selectedZone !== null && $player->hasPermission("afkzone.cmd")) {
                $this->openZoneDetailsUI($player, $selectedZone);
            } else if ($selectedZone !== null) {
                $zoneManager = $this->plugin->getZoneManager();
                if ($zoneManager->teleportToZone($player, $selectedZone)) {
                    $player->sendMessage(TF::GREEN . "You have been teleported to the AFKZone '$selectedZone'.");
                } else {
                    $player->sendMessage(TF::RED . "Failed to teleport to the zone.");
                }
            } else {
                $this->openMainUI($player);
            }
        });

        foreach (array_keys($zones) as $zoneName) {
            $form->addButton($zoneName);
        }

        $player->sendForm($form);
    }

    public function openZoneDetailsUI(Player $player, string $zoneName): void {
        $form = new SimpleForm("AFKZone: $zoneName", "What would you like to do with this zone?", function (Player $player, ?int $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneListUI($player);
                return;
            }

            $zoneManager = $this->plugin->getZoneManager();

            switch ($data) {
                case 0:
                    if ($zoneManager->teleportToZone($player, $zoneName)) {
                        $player->sendMessage(TF::GREEN . "You have been teleported to the AFKZone '$zoneName'.");
                    } else {
                        $player->sendMessage(TF::RED . "Failed to teleport to the zone.");
                    }
                    break;

                case 1:
                    $this->openZoneRewardsUI($player, $zoneName);
                    break;

                case 2:
                    $zoneManager->deleteZone($zoneName);
                    $player->sendMessage(TF::GREEN . "AFKZone '$zoneName' has been deleted.");
                    $this->openZoneListUI($player);
                    break;

                case 3:
                    $this->openZoneListUI($player);
                    break;
            }
        });

        $form->addButton("Teleport to AFKZone");
        $form->addButton("Configure Rewards");
        $form->addButton("Delete AFKZone");
        $form->addButton("Back to List");

        $player->sendForm($form);
    }

    public function openZoneRewardsUI(Player $player, string $zoneName): void {
        $zoneManager = $this->plugin->getZoneManager();
        $rewards = $zoneManager->getZoneRewards($zoneName);
        $money = $rewards["money"] ?? 100;
        $items = $rewards["items"] ?? [];

        $form = new SimpleForm("Configure Rewards: $zoneName", "Choose what to customize:", function (Player $player, ?int $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneDetailsUI($player, $zoneName);
                return;
            }

            switch ($data) {
                case 0:
                    $this->openMoneyRewardUI($player, $zoneName);
                    break;

                case 1:
                    $this->openItemRewardUI($player, $zoneName);
                    break;

                case 2:
                    $this->openZoneDetailsUI($player, $zoneName);
                    break;
            }
        });

        $form->addButton("Money Rewards");
        $form->addButton("Item Rewards");
        $form->addButton("Back");

        $player->sendForm($form);
    }

    public function openMoneyRewardUI(Player $player, string $zoneName): void {
        $zoneManager = $this->plugin->getZoneManager();
        $rewards = $zoneManager->getZoneRewards($zoneName);
        $money = $rewards["money"] ?? 100;

        $form = new CustomForm("Money Reward: $zoneName", function (Player $player, ?array $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneRewardsUI($player, $zoneName);
                return;
            }

            $zoneManager = $this->plugin->getZoneManager();
            $money = (int)($data[0] ?? 100);
            if ($money === 0) {
                $money = 100;
            }

            if ($money < 0) {
                $player->sendMessage(TF::RED . "Money reward cannot be negative!");
                return;
            }

            $rewards = $zoneManager->getZoneRewards($zoneName);
            $items = $rewards["items"] ?? [];
            $zoneManager->setZoneRewards($zoneName, $money, $items);
            $player->sendMessage(TF::GREEN . "Money reward updated to: " . TF::YELLOW . "$money");
            $this->openZoneRewardsUI($player, $zoneName);
        });

        $form->addInput("Money Rewards", "100", (string)$money);
        $player->sendForm($form);
    }

    public function openItemRewardUI(Player $player, string $zoneName): void {
        $form = new SimpleForm("Item Rewards: $zoneName", "Customize item reward:", function (Player $player, ?int $data) use ($zoneName) {
            if ($data === null) {
                $this->openZoneRewardsUI($player, $zoneName);
                return;
            }

            switch ($data) {
                case 0:
                    $this->openAddItemRewardUI($player, $zoneName);
                    break;

                case 1:
                    $this->openRemoveItemRewardUI($player, $zoneName);
                    break;

                case 2:
                    $this->openZoneRewardsUI($player, $zoneName);
                    break;
            }
        });

        $form->addButton("Add Item Reward");
        $form->addButton("Remove Item Reward");
        $form->addButton("Back");

        $player->sendForm($form);
    }

    public function openAddItemRewardUI(Player $player, string $zoneName): void {
        $form = new CustomForm("Add Item Reward: $zoneName", function (Player $player, ?array $data) use ($zoneName) {
            if ($data === null) {
                $this->openItemRewardUI($player, $zoneName);
                return;
            }

            $itemName = trim($data[0] ?? "");
            $amount = (int)($data[1] ?? 1);
            if ($amount === 0) {
                $amount = 1;
            }

            if (empty($itemName)) {
                $player->sendMessage(TF::RED . "Item name cannot be empty!");
                return;
            }

            if ($amount < 1) {
                $player->sendMessage(TF::RED . "Amount must be at least 1!");
                return;
            }

            $zoneManager = $this->plugin->getZoneManager();
            $rewards = $zoneManager->getZoneRewards($zoneName);
            $items = $rewards["items"] ?? [];
            $money = $rewards["money"] ?? 100;

            $items[] = [
                "item" => strtolower(str_replace(" ", "_", $itemName)),
                "amount" => $amount
            ];

            $zoneManager->setZoneRewards($zoneName, $money, $items);
            $player->sendMessage(TF::GREEN . "Item reward added: " . TF::YELLOW . "$itemName x$amount");
            $this->openItemRewardUI($player, $zoneName);
        });

        $form->addInput("Item Name", "diamond", "");
        $form->addInput("Amount", "1", "");
        $player->sendForm($form);
    }

    public function openRemoveItemRewardUI(Player $player, string $zoneName): void {
        $zoneManager = $this->plugin->getZoneManager();
        $rewards = $zoneManager->getZoneRewards($zoneName);
        $items = $rewards["items"] ?? [];

        if (empty($items)) {
            $player->sendMessage(TF::YELLOW . "This zone has no custom item rewards.");
            $this->openItemRewardUI($player, $zoneName);
            return;
        }

        $form = new SimpleForm("Remove Item Reward: $zoneName", "Select item to remove:", function (Player $player, ?int $data) use ($zoneName, $items) {
            if ($data === null) {
                $this->openItemRewardUI($player, $zoneName);
                return;
            }

            if (!isset($items[$data])) {
                $this->openItemRewardUI($player, $zoneName);
                return;
            }

            $zoneManager = $this->plugin->getZoneManager();
            $rewards = $zoneManager->getZoneRewards($zoneName);
            $money = $rewards["money"] ?? 100;

            unset($items[$data]);
            $items = array_values($items);

            $zoneManager->setZoneRewards($zoneName, $money, $items);
            $player->sendMessage(TF::GREEN . "Item reward removed!");
            $this->openItemRewardUI($player, $zoneName);
        });

        foreach ($items as $itemData) {
            $itemName = $itemData["item"] ?? "unknown";
            $amount = $itemData["amount"] ?? 1;
            $form->addButton("$itemName x$amount");
        }

        $player->sendForm($form);
    }
}

