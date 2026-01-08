<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\entity;

use pocketmine\math\Vector3;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use LuthMC\AFKZone\Main;

class FloatingTextHandler {

    private array $floatingTexts = [];

    public function __construct(
        private Main $plugin,
        private Config $leaderboardConfig
    ) {}

    public function loadFloatingTexts(): void {
        foreach ($this->leaderboardConfig->getAll() as $name => $data) {
            $this->loadFloatingText($name);
        }
    }

    private function loadFloatingText(string $name): void {
        $data = $this->leaderboardConfig->get($name);

        if ($data === null) {
            return;
        }

        $worldName = $data["position"]["world"];
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($worldName);

        if ($world === null) {
            $this->plugin->getLogger()->warning("Could not load leaderboard for '$name' because world '$worldName' is not loaded.");
            return;
        }

        $position = new Vector3(
            $data["position"]["x"],
            $data["position"]["y"],
            $data["position"]["z"]
        );

        if (isset($this->floatingTexts[$name])) {
            $this->floatingTexts[$name]->close();
        }

        $title = $data["title"];
        $maxEntries = $data["maxEntries"];
        $text = $this->generateLeaderboardText($title, $maxEntries);
        $entity = new FloatingTextEntity($position, $text, $world);
        $entity->spawnToAll();

        $this->floatingTexts[$name] = $entity;
    }

    public function updateLeaderboards(): void {
        foreach ($this->floatingTexts as $name => $entity) {
            $data = $this->leaderboardConfig->get($name);

            if ($data === null) {
                continue;
            }

            $title = $data["title"];
            $maxEntries = $data["maxEntries"];
            $newText = $this->generateLeaderboardText($title, $maxEntries);
            $entity->setText($newText);
        }
    }

    private function generateLeaderboardText(string $title, int $maxEntries): string {
        $text = TF::BOLD . TF::YELLOW . $title . TF::RESET . "\n";
        $text .= TF::GRAY . "Updated: " . date("H:i:s") . "\n\n";
        $sortedPlayers = $this->plugin->getPlayerAFKTimes();
        arsort($sortedPlayers);

        $count = 0;
        foreach ($sortedPlayers as $playerName => $time) {
            if ($count >= $maxEntries) {
                break;
            }

            $timeFormatted = $this->formatTime($time);
            $text .= TF::AQUA . "#" . ($count + 1) . " " . TF::WHITE . $playerName . " - " . TF::YELLOW . $timeFormatted . "\n";
            $count++;
        }

        if ($count === 0) {
            $text .= TF::GRAY . "No players have used AFKZones yet.";
        }

        return $text;
    }

    private function formatTime(int $seconds): string {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $seconds = $seconds % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    public function getAllLeaderboards(): array {
        return $this->leaderboardConfig->getAll();
    }
}
