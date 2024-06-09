<?php

declare(strict_types=1);

namespace Luthfi\AFKZone;

use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\ScoreHud;
use Ifera\ScoreHud\lib\scoreboard\ScoreTag;
use pocketmine\event\Listener;
use pocketmine\event\HandlerList;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

class ScoreHudListener implements Listener {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
    }

    public function onTagResolve(TagsResolveEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();

        if ($event->getTag() === "{afkzone.time}") {
            $time = $this->plugin->afkTimes[$name] ?? 0;
            $hours = floor($time / 3600);
            $minutes = floor(($time % 3600) / 60);
            $seconds = $time % 60;
            $afkTime = "{$hours}h {$minutes}m {$seconds}s";
            $event->setValue($afkTime);
        }
    }
}
