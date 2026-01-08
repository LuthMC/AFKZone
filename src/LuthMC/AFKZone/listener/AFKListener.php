<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use LuthMC\AFKZone\Main;

class AFKListener implements Listener {

    private array $playerInZone = [];

    public function __construct(private Main $plugin) {}

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        $zoneManager = $this->plugin->getZoneManager();

        $isInZone = $zoneManager->isPlayerInAFKZone($player);
        $wasInZone = $this->playerInZone[$playerName] ?? false;

        if ($isInZone && !$wasInZone) {
            $this->playerInZone[$playerName] = true;
            $this->plugin->notifyEnter($player);
            $this->plugin->addPlayerAFKTime($playerName, 0);
        } elseif (!$isInZone && $wasInZone) {
            unset($this->playerInZone[$playerName]);
            $this->plugin->notifyLeave($player);
        }
    }
}

