<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social;


use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UnknownItemException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\FollowInterface;
use OCA\Social\Interfaces\Activity\LikeInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Interfaces\IActivityPubInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Follow;
use OCA\Social\Model\ActivityPub\Activity\Like;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\QueryException;


/**
 * Class AP
 *
 * @package OCA\Social
 */
class AP {


	use TArrayTools;


	const REDUNDANCY_LIMIT = 10;


	/** @var AcceptInterface */
	public $acceptInterface;

	/** @var AddInterface */
	public $addInterface;

	/** @var BlockInterface */
	public $blockInterface;

	/** @var CreateInterface */
	public $createInterface;

	/** @var DeleteInterface */
	public $deleteInterface;

	/** @var FollowInterface */
	public $followInterface;

	/** @var LikeInterface */
	public $likeInterface;

	/** @var PersonInterface */
	public $personInterface;

	/** @var NoteInterface */
	public $noteInterface;

	/** @var RejectInterface */
	public $rejectInterface;

	/** @var RemoveInterface */
	public $removeInterface;

	/** @var UndoInterface */
	public $undoInterface;

	/** @var UpdateInterface */
	public $updateInterface;

	/** @var NotificationInterface */
	public $notificationInterface;

	/** @var ConfigService */
	public $configService;


	/** @var AP */
	public static $activityPub = null;


	/**
	 * AP constructor.
	 */
	public function __construct() {
	}


	/**
	 *
	 */
	public static function init() {
		$ap = new AP();
		try {
			$ap->acceptInterface = \OC::$server->query(AcceptInterface::class);
			$ap->addInterface = \OC::$server->query(AddInterface::class);
			$ap->blockInterface = \OC::$server->query(BlockInterface::class);
			$ap->createInterface = \OC::$server->query(CreateInterface::class);
			$ap->deleteInterface = \OC::$server->query(DeleteInterface::class);
			$ap->followInterface = \OC::$server->query(FollowInterface::class);
			$ap->likeInterface = \OC::$server->query(LikeInterface::class);
			$ap->noteInterface = \OC::$server->query(NoteInterface::class);
			$ap->notificationInterface = \OC::$server->query(SocialAppNotificationInterface::class);
			$ap->personInterface = \OC::$server->query(PersonInterface::class);
			$ap->rejectInterface = \OC::$server->query(RejectInterface::class);
			$ap->removeInterface = \OC::$server->query(RemoveInterface::class);
			$ap->undoInterface = \OC::$server->query(UndoInterface::class);
			$ap->updateInterface = \OC::$server->query(UpdateInterface::class);

			$ap->configService = \OC::$server->query(ConfigService::class);

			AP::$activityPub = $ap;
		} catch (QueryException $e) {
			\OC::$server->getLogger()
						->logException($e);
		}
	}


	/**
	 * @param array $data
	 * @param ACore $parent
	 * @param int $level
	 *
	 * @return ACore
	 * @throws RedundancyLimitException
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 */
	public function getItemFromData(array $data, $parent = null, int $level = 0): ACore {
		if (++$level > self::REDUNDANCY_LIMIT) {
			throw new RedundancyLimitException($level);
		}

		$item = $this->getSimpleItemFromData($data);
		if ($parent !== null) {
			$item->setParent($parent);
		}

		try {
			$object = $this->getItemFromData($this->getArray('object', $data, []), $item, $level);
			$item->setObject($object);
		} catch (UnknownItemException $e) {
		}

		try {
			/** @var Document $icon */
			$icon = $this->getItemFromData($this->getArray('icon', $data, []), $item, $level);
			$item->setIcon($icon);
		} catch (UnknownItemException $e) {
		}

		return $item;
	}


	/**
	 * @param array $data
	 *
	 * @return ACore
	 * @throws SocialAppConfigException
	 * @throws UnknownItemException
	 */
	public function getSimpleItemFromData(array $data): Acore {
		$item = $this->getItemFromType($this->get('type', $data, ''));
		$item->setUrlCloud($this->configService->getCloudAddress());
		$item->import($data);
		$item->setSource(json_encode($data, JSON_UNESCAPED_SLASHES));

		return $item;
	}

	/**
	 * @param string $type
	 *
	 * @return ACore
	 * @throws UnknownItemException
	 */
	public function getItemFromType(string $type) {
		switch ($type) {
			case Accept::TYPE:
				return new Accept();

			case Add::TYPE:
				return new Add();

			case Block::TYPE:
				return new Block();

			case Create::TYPE:
				return new Create();

			case Delete::TYPE:
				return new Delete();

			case Follow::TYPE:
				return new Follow();

			case Image::TYPE:
				return new Image();

			case Like::TYPE:
				return new Like();

			case Note::TYPE:
				return new Note();

			case SocialAppNotification::TYPE:
				return new SocialAppNotification();

			case Person::TYPE:
				return new Person();

			case Reject::TYPE:
				return new Reject();

			case Remove::TYPE:
				return new Remove();

			case Tombstone::TYPE:
				return new Tombstone();

			case Undo::TYPE:
				return new Undo();

			case Update::TYPE:
				return new Update();

			default:
				throw new UnknownItemException();
		}
	}


	/**
	 * @param ACore $activity
	 *
	 * @return IActivityPubInterface
	 * @throws UnknownItemException
	 */
	public function getInterfaceForItem(Acore $activity): IActivityPubInterface {
		return $this->getInterfaceFromType($activity->getType());
	}


	/**
	 * @param string $type
	 *
	 * @return IActivityPubInterface
	 * @throws UnknownItemException
	 */
	public function getInterfaceFromType(string $type): IActivityPubInterface {
		switch ($type) {
			case Accept::TYPE:
				$service = $this->acceptInterface;
				break;

			case Add::TYPE:
				$service = $this->addInterface;
				break;

			case Block::TYPE:
				$service = $this->blockInterface;
				break;

			case Create::TYPE:
				$service = $this->createInterface;
				break;

			case Delete::TYPE:
				$service = $this->deleteInterface;
				break;

			case Follow::TYPE:
				$service = $this->followInterface;
				break;

			case Like::TYPE:
				$service = $this->likeInterface;
				break;

			case Note::TYPE:
				$service = $this->noteInterface;
				break;

			case SocialAppNotification::TYPE:
				$service = $this->notificationInterface;
				break;

			case Person::TYPE:
				$service = $this->personInterface;
				break;

			case Reject::TYPE:
				$service = $this->rejectInterface;
				break;

			case Remove::TYPE:
				$service = $this->removeInterface;
				break;

			case Undo::TYPE:
				$service = $this->undoInterface;
				break;

			case Update::TYPE:
				$service = $this->updateInterface;
				break;

			default:
				throw new UnknownItemException();
		}

		return $service;
	}

}


AP::init();

