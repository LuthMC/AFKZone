<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\listener;

use pocketmine\event\Listener;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use LuthMC\AFKZone\Main;

class ScoreHudListener implements Listener {

    public function __construct(private Main $plugin) {}

    public function onTagResolve(TagsResolveEvent $event): void {
        $tag = $event->getTag();

        if ($tag->getName() === "afkzone.time") {
            $player = $event->getPlayer();
            $time = $this->plugin->getFormattedAFKTime($player->getName());
            $tag->setValue($time);
        }
    }
}
