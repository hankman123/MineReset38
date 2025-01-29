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

    protected bool $diff = true;

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
    ){}

    public function tryReset(): Generator {
        $event = new MineResetEvent($this, $this->diff);
        $event->call();

        if ($event->isCancelled() || ($this->diffReset && !$this->diff)) {
            return false;
        }

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

        for ($x = (int)$bb->minX; $x <= (int)$bb->maxX; $x++) {
            for ($z = (int)$bb->minZ; $z <= (int)$bb->maxZ; $z++) {
                for ($y = (int)$bb->minY; $y <= (int)$bb->maxY; $y++) {
                    if (!$world->isLoaded()) break 3;

                    if (++$count >= Main::$blockReplaceTick) {
                        $count = 0;
                        yield from $std->sleep(1);
                    }

                    $world->setBlockAt($x, $y, $z, $blocks[array_rand($blocks)], false);
                }
            }
        }

        return true;
    }

    public function getBlocksAsRandomArray(): Generator {
        $arr = [];
        $std = Main::getInstance()->getStd();

        foreach ($this->blocks as $block) {
            for ($i = 1; $i <= $block->chance; $i++) {
                $arr[] = $block->block;
            }
            yield from $std->sleep(1);
        }

        return $arr;
    }

    public function watchDiff(): Generator {
        $this->diff = false;
        $std = Main::getInstance()->getStd();

        $awaitBreak = [
            BlockBreakEvent::class,
            fn(BlockEvent $e) => $this->bb()->expand(.1, .1, .1)->isVectorInside($e->getBlock()->getPosition()),
            false,
            EventPriority::MONITOR,
            false
        ];

        $awaitExplode = [
            EntityExplodeEvent::class,
            function(EntityExplodeEvent $e): bool {
                foreach ($e->getBlockList() as $exploded) {
                    if ($this->bb()->expand(.1, .1, .1)->isVectorInside($exploded->getPosition())) {
                        return true;
                    }
                }
                return false;
            }
        ];

        $result = yield from Await::race([
            $std->awaitEvent(...$awaitBreak),
            $std->awaitEvent(...$awaitExplode),
        ]);

        $this->diff = true;

        return $result;
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

    /**
     * Returns a progress bar for the remaining mine reset time.
     *
     * @param int $barLength Length of the progress bar (default: 20)
     * @return string Progress bar as a string
     */
    public function getTimeLeftProgressBar(int $barLength = 20): string {
        $timeLeft = max(0, ($this->lastReset + $this->resetTime) - time());
        $percentage = ($this->resetTime > 0) ? min(1, $timeLeft / $this->resetTime) : 0;

        $filledBars = (int) round($barLength * (1 - $percentage));
        $emptyBars = $barLength - $filledBars;

        return str_repeat("█", $filledBars) . str_repeat("░", $emptyBars);
    }

    /**
     * Retrieves placeholder values for the plugin.
     *
     * @param string $identifier Placeholder identifier
     * @return string|null Placeholder value or null if not found
     */
    public function getPlaceholder(string $identifier): ?string {
        if (str_starts_with($identifier, "minereset38.time.")) {
            $mineName = substr($identifier, strlen("minereset38.time."));
            if ($mineName === $this->name) {
                return $this->getTimeLeftProgressBar();
            }
        }
        return null;
    }
}
