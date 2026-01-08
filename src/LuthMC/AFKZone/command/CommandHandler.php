<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use LuthMC\AFKZone\Main;

class CommandHandler {

    public function __construct(private Main $plugin) {}

    public function handle(CommandSender $sender, Command $command, string $label, array $args): bool {
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
                $this->plugin->getUIHandler()->openMainUI($sender);
                break;

            case "pos1":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                $this->setPosition($sender, 1);
                break;

            case "pos2":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }
                $this->setPosition($sender, 2);
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

                $this->createZone($sender, $args[1]);
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

                $this->deleteZone($sender, $args[1]);
                break;

            case "list":
                if (!$sender->hasPermission("afkzone.cmd")) {
                    $sender->sendMessage(TF::RED . "You don't have permission to use this command.");
                    return true;
                }

                $this->listZones($sender);
                break;

            default:
                $this->sendHelp($sender);
                break;
        }

        return true;
    }

    public function setPosition(Player $player, int $posNum): void {
        $pos = $player->getPosition();
        $zoneManager = $this->plugin->getZoneManager();
        $zoneManager->setPlayerPosition($player->getName(), $posNum, $pos);

        $message = $posNum === 1 ? "Position 1" : "Position 2";
        $player->sendMessage(TF::GREEN . "$message set to: " . TF::YELLOW . sprintf("(%.1f, %.1f, %.1f)", $pos->x, $pos->y, $pos->z));
    }

    private function createZone(Player $player, string $name): void {
        $zoneManager = $this->plugin->getZoneManager();

        if (!$zoneManager->hasPlayerPosition($player->getName(), 1) || !$zoneManager->hasPlayerPosition($player->getName(), 2)) {
            $player->sendMessage(TF::RED . "Please set both positions first using /afkzone pos1 and /afkzone pos2");
            return;
        }

        if ($zoneManager->zoneExists($name)) {
            $player->sendMessage(TF::RED . "An AFKZone with that name already exists.");
            return;
        }

        $zoneManager->createZone($name, $player->getName());
        $player->sendMessage(TF::GREEN . "AFKZone '$name' created successfully.");
    }

    private function deleteZone(Player $player, string $name): void {
        $zoneManager = $this->plugin->getZoneManager();

        if (!$zoneManager->zoneExists($name)) {
            $player->sendMessage(TF::RED . "AFKZone '$name' does not exist.");
            return;
        }

        $zoneManager->deleteZone($name);
        $player->sendMessage(TF::GREEN . "AFKZone '$name' has been deleted.");
    }

    private function listZones(Player $player): void {
        $zoneManager = $this->plugin->getZoneManager();
        $zones = $zoneManager->getAllZones();

        if (empty($zones)) {
            $player->sendMessage(TF::YELLOW . "There are no AFKZones created yet.");
            return;
        }

        $player->sendMessage(TF::GREEN . "===== AFKZones =====");
        foreach (array_keys($zones) as $zoneName) {
            $player->sendMessage(TF::YELLOW . $zoneName);
        }
    }

    private function sendHelp(Player $player): void {
        $player->sendMessage(TF::GREEN . "=== AFKZone Commands ===");
        $player->sendMessage(TF::YELLOW . "/afkzone ui " . TF::WHITE . "- Open the AFKZone UI");

        if ($player->hasPermission("afkzone.cmd")) {
            $player->sendMessage(TF::YELLOW . "/afkzone pos1 " . TF::WHITE . "- Set Position 1 to current location");
            $player->sendMessage(TF::YELLOW . "/afkzone pos2 " . TF::WHITE . "- Set Position 2 to current location");
            $player->sendMessage(TF::YELLOW . "/afkzone create <name> " . TF::WHITE . "- Create a new AFKZone");
            $player->sendMessage(TF::YELLOW . "/afkzone delete <name> " . TF::WHITE . "- Delete an AFKZone");
            $player->sendMessage(TF::YELLOW . "/afkzone list " . TF::WHITE . "- List all AFKZones");
        }
    }
}
