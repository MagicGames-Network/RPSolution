<?php

declare(strict_types=1);

namespace emretr1\rpsolution;

use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ResourcePackDataInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackChunkRequestPacket;
use pocketmine\network\mcpe\protocol\ResourcePackClientResponsePacket;

class Main extends PluginBase implements Listener
{
	/** @var PackSendEntry[] */
	public static array $packSendQueue = [];

	protected function onEnable(): void
	{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->saveDefaultConfig();
		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
			foreach (self::$packSendQueue as $entry) {
				$entry->tick();
			}
		}), (int) $this->getConfig()->get("rp-chunk-send-interval", 30));
	}

	public function getRpChunkSize(): int
	{
		return (int) $this->getConfig()->get("rp-chunk-size", 524288);
	}

	public function onPacketReceive(DataPacketReceiveEvent $event): void
	{
		$origin = $event->getOrigin();
		$player = $origin->getPlayer();
		$packet = $event->getPacket();
		if (!$player instanceof Player) return;
		if ($packet instanceof ResourcePackClientResponsePacket) {
			if ($packet->status === ResourcePackClientResponsePacket::STATUS_SEND_PACKS) {
				$event->cancel();

				$manager = $this->getServer()->getResourcePackManager();

				$playerName = $player->getName();
				self::$packSendQueue[$playerName] = $entry = new PackSendEntry($player);

				foreach ($packet->packIds as $uuid) {
					//dirty hack for mojang's dirty hack for versions
					$splitPos = strpos($uuid, "_");
					if ($splitPos !== false) {
						$uuid = substr($uuid, 0, $splitPos);
					}

					$pack = $manager->getPackById($uuid);
					if (!($pack instanceof ResourcePack)) {
						//Client requested a resource pack but we don't have it available on the server
						$player->kick("disconnectionScreen.resourcePack");
						$this->getServer()->getLogger()->debug("Got a resource pack request for unknown pack with UUID " . $uuid . ", available packs: " . implode(", ", $manager->getPackIdList()));

						return;
					}

					$pk = new ResourcePackDataInfoPacket();
					$pk->packId = $pack->getPackId();
					$pk->maxChunkSize = $this->getRpChunkSize();
					$pk->chunkCount = (int) ceil($pack->getPackSize() / $pk->maxChunkSize);
					$pk->compressedPackSize = $pack->getPackSize();
					$pk->sha256 = $pack->getSha256();
					$player->getNetworkSession()->sendDataPacket($pk);

					for ($i = 0; $i < $pk->chunkCount; $i++) {
						$pk2 = new ResourcePackChunkDataPacket();
						$pk2->packId = $pack->getPackId();
						$pk2->chunkIndex = $i;
						$pk2->data = $pack->getPackChunk($pk->maxChunkSize * $i, $pk->maxChunkSize);
						//$pk2->progress = ($pk->maxChunkSize * $i);

						$entry->addPacket($pk2);
					}
				}
			}
		} elseif ($packet instanceof ResourcePackChunkRequestPacket) {
			$event->cancel(); // dont rely on client
		}
	}
}

class PackSendEntry
{
	/** @var DataPacket[] */
	protected array $packets = [];
	/** @var Player */
	public Player $player;

	public function __construct(Player $player)
	{
		$this->player = $player;
	}

	public function addPacket(DataPacket $packet): void
	{
		$this->packets[] = $packet;
	}

	public function tick(): void
	{
		if (!$this->player->isConnected()) {
			unset(Main::$packSendQueue[$this->player->getName()]);
			return;
		}

		if ($next = array_shift($this->packets)) {
			if ($next instanceof ClientboundPacket) {
				$this->player->getNetworkSession()->sendDataPacket($next);
			}
		} else {
			unset(Main::$packSendQueue[$this->player->getName()]);
		}
	}
}
