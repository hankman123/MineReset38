<?php

declare(strict_types=1);

namespace kingofturkey38\minereset38;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use SOFe\AwaitStd\AwaitStd;
use CortexPE\Commando\PacketHooker;
use kingofturkey38\minereset38\commands\MineCommand;
use kingofturkey38\minereset38\mine\MineRegistry;
use kingofturkey38\minereset38\mine\Mine;

class Main extends PluginBase implements Listener {
    
    private static self $instance;
    private AwaitStd $std;
    public static $blockReplaceTick;
    private const CONFIG_VERSION = "0.0.2";

    protected function onLoad(): void {
        self::$instance = $this;
        $this->std = AwaitStd::init($this);
        MineRegistry::getInstance();
        $this->loadFiles();
    }

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->loadCheck();
        if (!PacketHooker::isRegistered()) {
            PacketHooker::register($this);
        }
        $this->getServer()->getCommandMap()->register("minereset38", new MineCommand);
    }

    public function onChat(PlayerChatEvent $event): void {
        $message = $event->getMessage();
        if (str_contains($message, "{minereset38.time.")) {
            $message = $this->replacePlaceholder($message);
            $event->setMessage($message);
        }
    }

    private function replacePlaceholder(string $message): string {
        preg_match_all("/{minereset38.time.(\w+)}/", $message, $matches);
        foreach ($matches[1] as $mineName) {
            $mine = MineRegistry::getInstance()->getMine($mineName);
            if ($mine instanceof Mine) {
                $replacement = $mine->getTimeLeft() . "s [" . $mine->getProgressBar() . "]";
                $message = str_replace("{minereset38.time.$mineName}", $replacement, $message);
            }
        }
        return $message;
    }

    public static function getInstance(): Main {
        return self::$instance;
    }

    public function getStd(): AwaitStd {
        return $this->std;
    }
}
