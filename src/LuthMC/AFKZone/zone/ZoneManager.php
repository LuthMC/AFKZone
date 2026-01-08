<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\zone;

use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use LuthMC\AFKZone\Main;

class ZoneManager {

    private array $playerPositions = [];

    public function __construct(
        private Main $plugin,
        private Config $afkZonesConfig
    ) {}

    public function setPlayerPosition(string $playerName, int $posNum, Position $position): void {
        if (!isset($this->playerPositions[$playerName])) {
            $this->playerPositions[$playerName] = [];
        }
        $this->playerPositions[$playerName]["pos$posNum"] = $position;
    }

    public function hasPlayerPosition(string $playerName, int $posNum): bool {
        return isset($this->playerPositions[$playerName]["pos$posNum"]);
    }

    public function createZone(string $zoneName, string $playerName): void {
        $pos1 = $this->playerPositions[$playerName]["pos1"];
        $pos2 = $this->playerPositions[$playerName]["pos2"];

        $this->afkZonesConfig->set($zoneName, [
            "world" => $pos1->getWorld()->getFolderName(),
            "pos1" => [$pos1->getX(), $pos1->getY(), $pos1->getZ()],
            "pos2" => [$pos2->getX(), $pos2->getY(), $pos2->getZ()]
        ]);

        $this->afkZonesConfig->save();
    }

    public function deleteZone(string $zoneName): void {
        $this->afkZonesConfig->remove($zoneName);
        $this->afkZonesConfig->save();
    }

    public function zoneExists(string $zoneName): bool {
        return $this->afkZonesConfig->exists($zoneName);
    }

    public function getAllZones(): array {
        return $this->afkZonesConfig->getAll();
    }

    public function getZoneData(string $zoneName): ?array {
        return $this->afkZonesConfig->get($zoneName);
    }

    public function isPlayerInAFKZone(Player $player): bool {
        foreach ($this->getAllZones() as $zoneData) {
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($zoneData["world"]);

            if ($world === null || $player->getWorld() !== $world) {
                continue;
            }

            $pos1 = new Vector3($zoneData["pos1"][0], $zoneData["pos1"][1], $zoneData["pos1"][2]);
            $pos2 = new Vector3($zoneData["pos2"][0], $zoneData["pos2"][1], $zoneData["pos2"][2]);
            $playerPos = $player->getPosition();

            if (
                $playerPos->x >= min($pos1->x, $pos2->x) && $playerPos->x <= max($pos1->x, $pos2->x) &&
                $playerPos->y >= min($pos1->y, $pos2->y) && $playerPos->y <= max($pos1->y, $pos2->y) &&
                $playerPos->z >= min($pos1->z, $pos2->z) && $playerPos->z <= max($pos1->z, $pos2->z)
            ) {
                return true;
            }
        }

        return false;
    }

    public function teleportToZone(Player $player, string $zoneName): bool {
        $zoneData = $this->getZoneData($zoneName);

        if ($zoneData === null) {
            return false;
        }

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($zoneData["world"]);

        if ($world === null) {
            return false;
        }

        $pos1 = new Vector3($zoneData["pos1"][0], $zoneData["pos1"][1], $zoneData["pos1"][2]);
        $pos2 = new Vector3($zoneData["pos2"][0], $zoneData["pos2"][1], $zoneData["pos2"][2]);

        $center = new Vector3(
            ($pos1->getX() + $pos2->getX()) / 2,
            ($pos1->getY() + $pos2->getY()) / 2,
            ($pos1->getZ() + $pos2->getZ()) / 2
        );

        $player->teleport($center);
        return true;
    }
}
