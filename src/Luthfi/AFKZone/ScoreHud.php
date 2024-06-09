<?php

declare(strict_types=1);

namespace Luthfi\AFKZone;

use Ifera\ScoreHud\event\TagResolveEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;

class ScoreHud implements Listener {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
    }

    /**
     * @param TagResolveEvent $event
     */
    public function onTagResolve(TagResolveEvent $event): void {
        $tag = $event->getTag();
        $player = $event->getPlayer();

        if ($tag->getName() === "afkzone.time") {
            $event->setValue($this->getAfkTime($player));
        }
    }

    /**
     * @param Player $player
     * @return string
     */
    private function getAfkTime(Player $player): string {
        $name = $player->getName();
        $time = $this->plugin->afkTimes[$name] ?? 0;

        $hours = floor($time / 3600);
        $minutes = floor(($time % 3600) / 60);
        $seconds = $time % 60;

        return "{$hours}h {$minutes}m {$seconds}s";
    }
}
