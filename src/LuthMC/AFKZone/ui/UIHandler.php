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
                    $zoneManager->deleteZone($zoneName);
                    $player->sendMessage(TF::GREEN . "AFKZone '$zoneName' has been deleted.");
                    $this->openZoneListUI($player);
                    break;

                case 2:
                    $this->openZoneListUI($player);
                    break;
            }
        });

        $form->addButton("Teleport to AFKZone");
        $form->addButton("Delete AFKZone");
        $form->addButton("Back to List");

        $player->sendForm($form);
    }
}

