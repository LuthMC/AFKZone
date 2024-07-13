<?php

namespace Luthfi\AFKZone\ScoreHud;

use Ifera\ScoreHud\event\player\PlayerTagsUpdateEvent;
use Ifera\ScoreHud\parties\PartyManager;
use Ifera\ScoreHud\tag\PluginTag;
use Ifera\ScoreHud\ScoreHud;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use Luthfi\AFKZone\Main;

class ScoreHudProvider extends PluginBase implements Listener {

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        ScoreHud::getInstance()->getTagManager()->registerTag(new PluginTag('afkzone.time', $this));
    }

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
