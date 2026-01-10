<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\listener;

use pocketmine\event\Listener;
use Ifera\ScoreHud\event\PlayerTagUpdateEvent;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use LuthMC\AFKZone\Main;
use pocketmine\player\Player;

class ScoreHudListener implements Listener {

    public function __construct(private Main $plugin) {}

    public function onTagResolve(TagsResolveEvent $event): void {
        $tag = $event->getTag();

        if ($tag->getName() === "afkzone.time") {
            $player = $event->getPlayer();
            $zoneManager = $this->plugin->getZoneManager();
            
            if ($zoneManager->isPlayerInAFKZone($player)) {
                $config = $this->plugin->getConfig();
                $rewardTimer = $config->get("rewards", [])["timer"] ?? 60;
                $time = $this->plugin->getPlayerAFKTime($player->getName());
                $timeInCycle = $time % $rewardTimer;
                $secondsUntilReward = max(1, $rewardTimer - $timeInCycle);
                $tag->setValue($secondsUntilReward . "s");
            } else {
                $tag->setValue("Not Found");
            }
        }
    }

    public function updatePlayerTag(Player $player, string $value): void {
        (new PlayerTagUpdateEvent($player, new ScoreTag("afkzone.time", $value)))->call();
    }
}

