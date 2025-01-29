<?php

declare(strict_types=1);

namespace kingofturkey38\minereset38\mine;

use Generator;
use JsonSerializable;

use pocketmine\Server;
use pocketmine\player\Player;

use pocketmine\event\EventPriority;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockEvent;
use pocketmine\event\entity\EntityExplodeEvent;

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\world\World;

use SOFe\AwaitGenerator\Await;

use kingofturkey38\minereset38\Main;
use kingofturkey38\minereset38\events\MineResetEvent;

class Mine implements JsonSerializable {

    /** @var bool $diff */
    protected bool $diff = true; // Initial reset for the mine.

    public bool $diffReset = true;

    public function __construct(
        public string $name,
        public Vector3 $pos1,
        public Vector3 $pos2,
        public string $world,
        public array $blocks,
        public int $resetTime,
        public int $lastReset,
        public bool $isResetting = false,
    ) {}

    public static function jsonDeserialize(array $data): self {
        $mine = new Mine(
            $data["name"],
            new Vector3(...$data["pos1"]),
            new Vector3(...$data["pos2"]),
            $data["world"],
            array_map(fn(array $v) => MineBlock::jsonDeserialize($v), $data["blocks"]),
            $data["resetTime"],
            $data["lastReset"],
        );
        $mine->diffReset = $data["diffReset"] ?? true;

        return $mine;
    }

    public function tryReset(): Generator {
        $event = new MineResetEvent($this, $this->diff);
        $event->call();

        if ($event->isCancelled()) return false;
        if ($this->diffReset && !$this->diff) return false;

        $this->lastReset = time();

        if (($world = Server::getInstance()->getWorldManager()->getWorldByName($this->world)) !== null) {
            Await::g2c($this->watchDiff());
            $prefix = Main::getPrefix();
            if (!is_string($prefix)) $prefix = "";
            $broadcast = trim(str_replace(["{mine}", "{prefix}"], [$this->name, $prefix], Main::getInstance()->getConfig()->getNested("messages.mine-reset-announcement")));
            if ($broadcast !== "") {
                Server::getInstance()->broadcastMessage($broadcast);
            }

            return yield from $this->reset($world);
        }

        return false;
    }

    private function bb(): AxisAlignedBB {
        return new AxisAlignedBB(
            min($this->pos1->getX(), $this->pos2->getX()),
            min($this->pos1->getY(), $this->pos2->getY()),
            min($this->pos1->getZ(), $this->pos2->getZ()),
            max($this->pos1->getX(), $this->pos2->getX()),
            max($this->pos1->getY(), $this->pos2->getY()),
            max($this->pos1->getZ(), $this->pos2->getZ())
        );
    }

    public function reset(World $world): Generator {
        $std = Main::getInstance()->getStd();
        $bb = $this->bb();

        if (Main::getInstance()->getConfig()->get("teleport-to-spawn")) {
            foreach ($world->getCollidingEntities($bb) as $e) {
                if ($e instanceof Player) {
                    $e->teleport($world->getSafeSpawn());
                }
            }
        }

        $blocks = yield from $this->getBlocksAsRandomArray();
        $count = 0;
        $total = 0;
        $started = time();

        for ($x = (int) $bb->minX; $x <= (int) $bb->maxX; $x++) {
            for ($z = (int) $bb->minZ; $z <= (int) $bb->maxZ; $z++) {
                for ($y = (int) $bb->minY; $y <= (int) $bb->maxY; $y++) {
                    if (!$world->isLoaded()) {
                        break 3;
                    }

                    $total++;
                    $count++;

                    if ($count >= Main::$blockReplaceTick) {
                        $count = 0;
                        yield from $std->sleep(1);
                    }

                    $set = $blocks[array_rand($blocks)];
                    $world->setBlockAt($x, $y, $z, $set, false);
                }
            }
        }

        return true;
    }

    public function getTimeLeft(): int {
        $elapsed = time() - $this->lastReset;
        return max(0, $this->resetTime - $elapsed);
    }

    public function getProgressBar(int $length = 10): string {
        $timeLeft = $this->getTimeLeft();
        $progress = max(0, min($length, round(($this->resetTime - $timeLeft) / $this->resetTime * $length)));
        return str_repeat("■", $progress) . str_repeat("□", $length - $progress);
    }

    public function jsonSerialize(): array {
        return [
            "name" => $this->name,
            "pos1" => [$this->pos1->getX(), $this->pos1->getY(), $this->pos1->getZ()],
            "pos2" => [$this->pos2->getX(), $this->pos2->getY(), $this->pos2->getZ()],
            "world" => $this->world,
            "blocks" => $this->blocks,
            "resetTime" => $this->resetTime,
            "lastReset" => $this->lastReset,
            "diffReset" => $this->diffReset,
        ];
    }
}
