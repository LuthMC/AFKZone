<?php

namespace Luthfi\AFKZone;

use Ifera\ScoreHud\event\player\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\parties\PartyManager;
use Ifera\ScoreHud\tag\PluginTag;
use Ifera\ScoreHud\ScoreHud;
use pocketmine\event\Listener;
use pocketmine\Server;

class ScoreHudProvider implements Listener {

    /**
     * Handles the PlayerTagsUpdateEvent to update the custom tag values
     *
     * @param PlayerTagsUpdateEvent $event
     */
    public function onUpdateTags(PlayerTagsUpdateEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();

        if (isset($this->playersInZone[$name])) {
            $timeInZone = time() - $this->playersInZone[$name];
            $hours = floor($timeInZone / 3600);
            $minutes = floor(($timeInZone % 3600) / 60);
            $seconds = $timeInZone % 60;

            $event->addTag(new PluginTag('afkzone.time', "{$hours}h, {$minutes}m, {$seconds}s"));
        } else {
            $event->addTag(new PluginTag('afkzone.time', "0h, 0m, 0s"));
        }
    }
}
