<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\inventory;

use pocketmine\inventory\DropItemTransaction;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\Server;

/**
 * This TransactionGroup only allows doing Transaction between one / two inventories
 */
class SimpleTransactionGroup implements TransactionGroup{
	
	const DEFAULT_ALLOWED_RETRIES = 5;
	//const MAX_QUEUE_LENGTH = 3;
	
	/** @var Player[] */
	protected $player = null;
	
	/** @var \SplQueue */
	protected $transactionQueue;
	/** @var \SplQueue */
	protected $transactionsToRetry;
	
	/** @var bool */
	protected $isExecuting = false;
	
	/** @var float */
	protected $lastUpdate = -1;
	
	/** @var Inventory[] */	
	protected $inventories = [];
	
	/**
	 * @param Player $player
	 */
	public function __construct(Player $player = null){
		$this->player = $player;
		$this->transactionQueue = new \SplQueue();
		$this->transactionsToRetry = new \SplQueue();
	}
	/**
	 * @return Player
	 */
	public function getPlayer(){
		return $this->player;
	}
	
	/**
	 * @return \SplQueue
	 */
	public function getTransactions(){
		return $this->transactionQueue;
	}
	
	/**
	 * @return Inventory[]
	 */
	/*public function getInventories(){
		return $this->inventories;
	}*/
	
	/**
	 * @return bool
	 */
	public function isExecuting(){
		return $this->isExecuting;
	}
	
	/**
	 * @param Transaction $transaction
	 * @return bool
	 *
	 * Adds a transaction to the queue
	 * Returns true if the addition was successful, false if not.
	 */
	public function addTransaction(Transaction $transaction){
		$this->transactionQueue->enqueue($transaction);
		$this->lastUpdate = microtime(true);
		
		return true;
	}
	
	/** 
	 * @param Transaction 	$transaction
	 * @param Transaction[] &$completed
	 *
	 * Handles a failed transaction
	 */
	private function handleFailure(Transaction $transaction, &$failed){
		$transaction->addFailure();
		if($transaction->getFailures() >= self::DEFAULT_ALLOWED_RETRIES){
			//Transaction failed after several retries
			echo "transaction completely failed\n";
			$failed[] = $transaction;
		}else{
			//Add the transaction to the back of the queue to be retried
			$this->transactionsToRetry->enqueue($transaction);
		}
	}
	
	/**
	 * @return bool
	 *
	 * Handles transaction queue execution
	 */
	public function execute(){
		
		/** @var Transaction[] */
		$failed = [];
		
		$this->isExecuting = true;
		
		$failCount = $this->transactionsToRetry->count();
		while(!$this->transactionsToRetry->isEmpty()){
			//Some failed transactions are waiting from the previous execution to be retried
			$this->transactionQueue->enqueue($this->transactionsToRetry->dequeue());
		}
		
		if($this->transactionQueue->count() !== 0){
			echo "Batch-handling ".$this->transactionQueue->count()." changes, with ".$failCount." retries.\n";
		}
		
		while(!$this->transactionQueue->isEmpty()){
			
			$transaction = $this->transactionQueue->dequeue();
				
			$change = $transaction->getChange();
			if($change["out"] instanceof Item){
				if(!$this->player->getServer()->allowInventoryCheats){
					if($transaction->getInventory()->slotContains($transaction->getSlot(), $change["out"]) and !$this->player->isCreative()){
						//Do not add items to the crafting inventory in creative to prevent weird duplication bugs.
						$this->player->getCraftingInventory()->addItem($change["out"]);
						
					}elseif(!$player->isCreative()){ //Transaction failed, if the player is not in creative then this needs to be retried.
						$this->handleFailure($transaction, $failed);
						continue;
					}
				}
				$transaction->getInventory()->setItem($transaction->getSlot(), $transaction->getTargetItem(), false);
			}
			if($change["in"] instanceof Item){
				if(!$this->player->getServer()->allowInventoryCheats){
					if($this->player->getCraftingInventory()->contains($change["in"]) and !$player->isCreative()){
						$this->player->getCraftingInventory()->removeItem($change["in"]);
						
					}elseif(!$this->player->isCreative()){ //Transaction failed, if the player was not creative then transaction is illegal
						$this->handleFailure($transaction, $failed);
						continue;
					}
				}
				
				if($transaction instanceof DropItemTransaction){
					$this->player->dropItem($transaction->getTargetItem());
				}else{
					$transaction->getInventory()->setItem($transaction->getSlot(), $transaction->getTargetItem(), false);
				}
			}
			$transaction->setSuccess();
			$transaction->sendSlotUpdate($this->player);
		}
		$this->isExecuting = false;
		foreach($failed as $f){
			$f->sendSlotUpdate($this->player);
		}
		
		$this->lastExecution = microtime(true);
		$this->hasExecuted = true;
		return true;
	}
}
