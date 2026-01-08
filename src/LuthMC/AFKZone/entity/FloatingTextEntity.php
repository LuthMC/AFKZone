<?php

declare(strict_types=1);

namespace LuthMC\AFKZone\entity;

use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class FloatingTextEntity extends Entity {
    protected string $text;

    public function __construct(Vector3 $position, string $text, \pocketmine\world\World $world) {
        parent::__construct($position, $world);
        $this->text = $text;
        $this->setNameTag($text);
        $this->setNameTagAlwaysVisible(true);
        $this->setScale(0.01);
    }

    public function setText(string $text): void {
        $this->text = $text;
        $this->setNameTag($text);
        $this->sendData($this->getViewers());
    }

    public function getInitialDragMultiplier(): float {
        return 0.02;
    }

    public function getInitialGravity(): float {
        return 0.08;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(0.25, 0.25);
    }

    public static function getNetworkTypeId(): string {
        return EntityIds::ITEM;
    }
}
