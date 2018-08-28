<?php
/**
 *  ______  __         ______               __    __
 * |   __ \|__|.-----.|   __ \.----..-----.|  |_ |  |--..-----..----.
 * |   __ <|  ||  _  ||   __ <|   _||  _  ||   _||     ||  -__||   _|
 * |______/|__||___  ||______/|__|  |_____||____||__|__||_____||__|
 *             |_____|
 *
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014-2015 shoghicp <https://github.com/shoghicp/BigBrother>
 * Copyright (C) 2016- BigBrotherTeam
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author BigBrotherTeam
 * @link   https://github.com/BigBrotherTeam/BigBrother
 *
 */

declare(strict_types=1);

namespace shoghicp\BigBrother\network;

use pocketmine\{
	Player, Server
};
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\NetworkInterface;
use pocketmine\utils\MainLogger;
use pocketmine\network\mcpe\NetworkSession;
use shoghicp\BigBrother\{
	BigBrother, DesktopPlayer
};
use shoghicp\BigBrother\network\protocol\Login\{
	EncryptionResponsePacket, LoginStartPacket
};
use shoghicp\BigBrother\network\protocol\Play\Client\{
	AdvancementTabPacket, AnimatePacket, ChatPacket, ClickWindowPacket, ClientSettingsPacket, ClientStatusPacket, CloseWindowPacket, ConfirmTransactionPacket, CraftingBookDataPacket, CraftRecipeRequestPacket, CreativeInventoryActionPacket, EnchantItemPacket, EntityActionPacket, HeldItemChangePacket, KeepAlivePacket, PlayerAbilitiesPacket, PlayerBlockPlacementPacket, PlayerDiggingPacket, PlayerLookPacket, PlayerPacket, PlayerPositionAndLookPacket, PlayerPositionPacket, PluginMessagePacket, TabCompletePacket, TeleportConfirmPacket, UpdateSignPacket, UseEntityPacket, UseItemPacket
};
use shoghicp\BigBrother\utils\Binary;

class ProtocolInterface implements NetworkInterface{
	/** @var BigBrother */
	protected $plugin;

	/** @var Server */
	protected $server;

	/** @var Translator */
	protected $translator;

	/** @var ServerThread */
	protected $thread;

	/** @var \SplObjectStorage<DesktopPlayer> */
	protected $sessions;

	/** @var DesktopPlayer[] */
	protected $sessionsPlayers = [];

	/** @var DesktopPlayer[] */
	protected $identifiers = [];

	/** @var int */
	protected $identifier = 0;

	/** @var int */
	private $threshold;

	/**
	 * @param BigBrother $plugin
	 * @param Server     $server
	 * @param Translator $translator
	 * @param int        $threshold
	 */
	public function __construct(BigBrother $plugin, Server $server, Translator $translator, int $threshold){
		$this->plugin = $plugin;
		$this->server = $server;
		$this->translator = $translator;
		$this->threshold = $threshold;
		$this->thread = new ServerThread($server->getLogger(), $server->getLoader(), $plugin->getPort(), $plugin->getIp(), $plugin->getMotd(), $plugin->getDataFolder() . "server-icon.png", false);
		$this->sessions = new \SplObjectStorage();
	}

	/**
	 * @override
	 */
	public function start() : void{
		$this->thread->start();
	}

	/**
	 * @override
	 */
	public function emergencyShutdown() : void{
		$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_EMERGENCY_SHUTDOWN));
	}

	/**
	 * @override
	 */
	public function shutdown() : void{
		$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_SHUTDOWN));
	}

	/**
	 * @param string $name
	 *
	 * @override
	 */
	public function setName(string $name) : void{
		$info = $this->plugin->getServer()->getQueryInformation();
		$value = [
			"MaxPlayers" => $info->getMaxPlayerCount(),
			"OnlinePlayers" => $info->getPlayerCount(),
		];
		$buffer = chr(ServerManager::PACKET_SET_OPTION) . chr(strlen("name")) . "name" . json_encode($value);
		$this->thread->pushMainToThreadPacket($buffer);
	}

	/**
	 * @param int $identifier
	 */
	public function closeSession(int $identifier) : void{
		if(isset($this->sessionsPlayers[$identifier])){
			$player = $this->sessionsPlayers[$identifier];
			unset($this->sessionsPlayers[$identifier]);
			$player->close($player->getLeaveMessage(), "Connection closed");
		}
	}

	/**
	 * @param Player $player
	 * @param string $reason
	 *
	 * @override
	 */
	public function close(Player $player, string $reason = "unknown reason") : void{
		if(isset($this->sessions[$player])){
			$identifier = $this->sessions[$player];
			$this->sessions->detach($player);
			unset($this->identifiers[$identifier]);
			$this->thread->pushMainToThreadPacket(chr(ServerManager::PACKET_CLOSE_SESSION) . Binary::writeInt($identifier));
		}
	}

	/**
	 * @param int    $target
	 * @param Packet $packet
	 */
	protected function sendPacket(int $target, Packet $packet) : void{
		if(\pocketmine\DEBUG > 4){
			$id = bin2hex(chr($packet->pid()));
			if($id !== "1f"){
				echo "[Send][Interface] 0x" . bin2hex(chr($packet->pid())) . "\n";
			}
		}

		$data = chr(ServerManager::PACKET_SEND_PACKET) . Binary::writeInt($target) . $packet->write();
		$this->thread->pushMainToThreadPacket($data);
	}

	/**
	 * @param DesktopPlayer $player
	 */
	public function setCompression(DesktopPlayer $player) : void{
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$data = chr(ServerManager::PACKET_SET_COMPRESSION) . Binary::writeInt($target) . Binary::writeInt($this->threshold);
			$this->thread->pushMainToThreadPacket($data);
		}
	}

	/**
	 * @param DesktopPlayer $player
	 * @param string        $secret
	 */
	public function enableEncryption(DesktopPlayer $player, string $secret) : void{
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$data = chr(ServerManager::PACKET_ENABLE_ENCRYPTION) . Binary::writeInt($target) . $secret;
			$this->thread->pushMainToThreadPacket($data);
		}
	}

	/**
	 * @param DesktopPlayer $player
	 * @param Packet        $packet
	 */
	public function putRawPacket(DesktopPlayer $player, Packet $packet) : void{
		if(isset($this->sessions[$player])){
			$target = $this->sessions[$player];
			$this->sendPacket($target, $packet);
		}
	}

	/**
	 * @param Player     $player
	 * @param DataPacket $packet
	 * @param bool       $needACK
	 * @param bool       $immediate
	 *
	 * @return int|null identifier if $needAck === false else null
	 * @override
	 */
	public function putPacket(NetworkSession $session, DataPacket $packet, bool $immediate = true) : void{
		$id = 0;
		$player = ($RefectionNetworkSession = new \ReflectionObject($session))->setAccessible(true);
		$player = $player->getValue($session);
		$packets = $this->translator->serverToInterface($player, $packet);
		if($packets !== null and $this->sessions->contains($player)){
			$target = $this->sessions[$player];
			if(is_array($packets)){
				foreach($packets as $packet){
					$this->sendPacket($target, $packet);
				}
			}else{
				$this->sendPacket($target, $packets);
			}
		}
	}

	/**
	 * @param DesktopPlayer $player
	 * @param Packet        $packet
	 */
	protected function receivePacket(DesktopPlayer $player, Packet $packet) : void{
		$packets = $this->translator->interfaceToServer($player, $packet);
		if($packets !== null){
			if(is_array($packets)){
				foreach($packets as $packet){
					$player->handleDataPacket($packet);
				}
			}else{
				$player->handleDataPacket($packets);
			}
		}
	}

	/**
	 * @param DesktopPlayer $player
	 * @param string        $payload
	 */
	protected function handlePacket(DesktopPlayer $player, string $payload) : void{
		if(\pocketmine\DEBUG > 4){
			$id = bin2hex(chr(ord($payload{0})));
			if($id !== "0b"){//KeepAlivePacket
				echo "[Receive][Interface] 0x" . bin2hex(chr(ord($payload{0}))) . "\n";
			}
		}

		$pid = ord($payload{0});
		$offset = 1;

		$status = $player->bigBrother_getStatus();

		if($status === 1){
			switch($pid){
				case InboundPacket::TELEPORT_CONFIRM_PACKET:
					$pk = new TeleportConfirmPacket();
					break;
				case InboundPacket::TAB_COMPLETE_PACKET:
					$pk = new TabCompletePacket();
					break;
				case InboundPacket::CHAT_PACKET:
					$pk = new ChatPacket();
					break;
				case InboundPacket::CLIENT_STATUS_PACKET:
					$pk = new ClientStatusPacket();
					break;
				case InboundPacket::CLIENT_SETTINGS_PACKET:
					$pk = new ClientSettingsPacket();
					break;
				case InboundPacket::CONFIRM_TRANSACTION_PACKET:
					$pk = new ConfirmTransactionPacket();
					break;
				case InboundPacket::ENCHANT_ITEM_PACKET:
					$pk = new EnchantItemPacket();
					break;
				case InboundPacket::CLICK_WINDOW_PACKET:
					$pk = new ClickWindowPacket();
					break;
				case InboundPacket::CLOSE_WINDOW_PACKET:
					$pk = new CloseWindowPacket();
					break;
				case InboundPacket::PLUGIN_MESSAGE_PACKET:
					$pk = new PluginMessagePacket();
					break;
				case InboundPacket::USE_ENTITY_PACKET:
					$pk = new UseEntityPacket();
					break;
				case InboundPacket::KEEP_ALIVE_PACKET:
					$pk = new KeepAlivePacket();
					break;
				case InboundPacket::PLAYER_PACKET:
					$pk = new PlayerPacket();
					break;
				case InboundPacket::PLAYER_POSITION_PACKET:
					$pk = new PlayerPositionPacket();
					break;
				case InboundPacket::PLAYER_POSITION_AND_LOOK_PACKET:
					$pk = new PlayerPositionAndLookPacket();
					break;
				case InboundPacket::PLAYER_LOOK_PACKET:
					$pk = new PlayerLookPacket();
					break;
				case InboundPacket::CRAFT_RECIPE_REQUEST_PACKET:
					$pk = new CraftRecipeRequestPacket();
					break;
				case InboundPacket::PLAYER_ABILITIES_PACKET:
					$pk = new PlayerAbilitiesPacket();
					break;
				case InboundPacket::PLAYER_DIGGING_PACKET:
					$pk = new PlayerDiggingPacket();
					break;
				case InboundPacket::ENTITY_ACTION_PACKET:
					$pk = new EntityActionPacket();
					break;
				case InboundPacket::CRAFTING_BOOK_DATA_PACKET:
					$pk = new CraftingBookDataPacket();
					break;
				case InboundPacket::ADVANCEMENT_TAB_PACKET:
					$pk = new AdvancementTabPacket();
					break;
				case InboundPacket::HELD_ITEM_CHANGE_PACKET:
					$pk = new HeldItemChangePacket();
					break;
				case InboundPacket::CREATIVE_INVENTORY_ACTION_PACKET:
					$pk = new CreativeInventoryActionPacket();
					break;
				case InboundPacket::UPDATE_SIGN_PACKET:
					$pk = new UpdateSignPacket();
					break;
				case InboundPacket::ANIMATE_PACKET:
					$pk = new AnimatePacket();
					break;
				case InboundPacket::PLAYER_BLOCK_PLACEMENT_PACKET:
					$pk = new PlayerBlockPlacementPacket();
					break;
				case InboundPacket::USE_ITEM_PACKET:
					$pk = new UseItemPacket();
					break;
				default:
					if(\pocketmine\DEBUG > 4){
						echo "[Receive][Interface] 0x" . bin2hex(chr($pid)) . " Not implemented\n"; //Debug
					}
					return;
			}

			$pk->read($payload, $offset);
			$this->receivePacket($player, $pk);
		}elseif($status === 0){
			if($pid === InboundPacket::LOGIN_START_PACKET){
				$pk = new LoginStartPacket();
				$pk->read($payload, $offset);
				$player->bigBrother_handleAuthentication($this->plugin, $pk->name, $this->plugin->isOnlineMode());
			}elseif($pid === InboundPacket::ENCRYPTION_RESPONSE_PACKET and $this->plugin->isOnlineMode()){
				$pk = new EncryptionResponsePacket();
				$pk->read($payload, $offset);
				$player->bigBrother_processAuthentication($this->plugin, $pk);
			}else{
				$player->close($player->getLeaveMessage(), "Unexpected packet $pid");
			}
		}
	}

	/**
	 * @override
	 */
	public function process() : void{
		if(count($this->identifiers) > 0){
			foreach($this->identifiers as $id => $player){
				$player->handleACK($id);
			}
		}

		while(is_string($buffer = $this->thread->readThreadToMainPacket())){
			$offset = 1;
			$pid = ord($buffer{0});

			if($pid === ServerManager::PACKET_SEND_PACKET){
				$id = Binary::readInt(substr($buffer, $offset, 4));
				$offset += 4;
				if(isset($this->sessionsPlayers[$id])){
					$payload = substr($buffer, $offset);
					try{
						$this->handlePacket($this->sessionsPlayers[$id], $payload);
					}catch(\Exception $e){
						if(\pocketmine\DEBUG > 1){
							$logger = $this->server->getLogger();
							if($logger instanceof MainLogger){
								$logger->debug("DesktopPacket 0x" . bin2hex($payload));
								$logger->logException($e);
							}
						}
					}
				}
			}elseif($pid === ServerManager::PACKET_OPEN_SESSION){
				$id = Binary::readInt(substr($buffer, $offset, 4));
				$offset += 4;
				if(isset($this->sessionsPlayers[$id])){
					continue;
				}
				$len = ord($buffer{$offset++});
				$address = substr($buffer, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($buffer, $offset, 2));

				$identifier = "$id:$address:$port";

				$player = new DesktopPlayer($this, $identifier, $address, $port, $this->plugin);
				$this->sessions->attach($player, $id);
				$this->sessionsPlayers[$id] = $player;
				$this->plugin->getServer()->addPlayer($player);
			}elseif($pid === ServerManager::PACKET_CLOSE_SESSION){
				$id = Binary::readInt(substr($buffer, $offset, 4));

				$this->closeSession($id);
			}
		}
	}
}
