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

declare(strict_types=1);

namespace pocketmine\network\mcpe\cache;

use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\FurnaceType;
use pocketmine\crafting\RecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\item\Item;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\CraftingDataPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipe as ProtocolFurnaceRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\PotionContainerChangeRecipe as ProtocolPotionContainerChangeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\PotionTypeRecipe as ProtocolPotionTypeRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient as ProtocolRecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\ShapedRecipe as ProtocolShapedRecipe;
use pocketmine\network\mcpe\protocol\types\recipe\ShapelessRecipe as ProtocolShapelessRecipe;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Binary;
use pocketmine\utils\ProtocolSingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function in_array;
use function spl_object_id;

final class CraftingDataCache{
	use ProtocolSingletonTrait;

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $caches = [];

	public function getCache(CraftingManager $manager) : string{
		$id = spl_object_id($manager);
		if(!isset($this->caches[$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$this->caches[$id] = $this->buildCraftingDataCache($manager);
		}
		return $this->caches[$id];
	}

	/**
	 * Rebuilds the cached CraftingDataPacket.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : string{
		Timings::$craftingDataCacheRebuild->startTiming();

		$nullUUID = Uuid::fromString(Uuid::NIL);
		$converter = TypeConverter::getInstance($this->protocolId);
		$recipesWithTypeIds = [];

		foreach($manager->getCraftingRecipeIndex() as $index => $recipe){
			if($recipe instanceof ShapelessRecipe){
				$typeTag = match($recipe->getType()->id()){
					ShapelessRecipeType::CRAFTING()->id() => CraftingRecipeBlockName::CRAFTING_TABLE,
					ShapelessRecipeType::STONECUTTER()->id() => CraftingRecipeBlockName::STONECUTTER,
					ShapelessRecipeType::CARTOGRAPHY()->id() => CraftingRecipeBlockName::CARTOGRAPHY_TABLE,
					ShapelessRecipeType::SMITHING()->id() => CraftingRecipeBlockName::SMITHING_TABLE,
					default => throw new AssumptionFailedError("Unreachable"),
				};

				$inputs = array_map(function(RecipeIngredient $item) use ($converter) : ?ProtocolRecipeIngredient{
					try{
						return $converter->coreRecipeIngredientToNet($item);
					}catch(\InvalidArgumentException|ItemTypeSerializeException){
						return null;
					}
				}, $recipe->getIngredientList());
				$outputs = array_map(function(Item $item) use ($converter) : ?ItemStack{
					try{
						return $converter->coreItemStackToNet($item);
					}catch(\InvalidArgumentException|ItemTypeSerializeException){
						return null;
					}
				}, $recipe->getResults());

				if(in_array(null, $inputs, true) || in_array(null, $outputs, true)){
					continue;
				}

				$recipesWithTypeIds[] = new ProtocolShapelessRecipe(
					CraftingDataPacket::ENTRY_SHAPELESS,
					Binary::writeInt($index),
					$inputs,
					$outputs,
					$nullUUID,
					$typeTag,
					50,
					$index
				);
			}elseif($recipe instanceof ShapedRecipe){
				$inputs = [];

				try{
					for($row = 0, $height = $recipe->getHeight(); $row < $height; ++$row){
						for($column = 0, $width = $recipe->getWidth(); $column < $width; ++$column){
							$inputs[$row][$column] = $converter->coreRecipeIngredientToNet($recipe->getIngredient($column, $row));
						}
					}
				}catch(\InvalidArgumentException|ItemTypeSerializeException){
					continue;
				}

				$outputs = array_map(function(Item $item) use ($converter) : ?ItemStack{
					try{
						return $converter->coreItemStackToNet($item);
					}catch(\InvalidArgumentException|ItemTypeSerializeException){
						return null;
					}
				}, $recipe->getResults());

				if(in_array(null, $outputs, true)){
					continue;
				}

				$recipesWithTypeIds[] = new ProtocolShapedRecipe(
					CraftingDataPacket::ENTRY_SHAPED,
					Binary::writeInt($index),
					$inputs,
					$outputs,
					$nullUUID,
					CraftingRecipeBlockName::CRAFTING_TABLE,
					50,
					$index
				);
			}else{
				//TODO: probably special recipe types
			}
		}

		foreach(FurnaceType::getAll() as $furnaceType){
			$typeTag = match($furnaceType->id()){
				FurnaceType::FURNACE()->id() => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE()->id() => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER()->id() => FurnaceRecipeBlockName::SMOKER,
				ShapelessRecipeType::SMITHING()->id() => CraftingRecipeBlockName::SMITHING_TABLE,
				default => throw new AssumptionFailedError("Unreachable"),
			};
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				try{
					$input = $converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor();
					$output = $converter->coreItemStackToNet($recipe->getResult());
				}catch(\InvalidArgumentException|ItemTypeSerializeException){
					continue;
				}

				if(!$input instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$recipesWithTypeIds[] = new ProtocolFurnaceRecipe(
					CraftingDataPacket::ENTRY_FURNACE_DATA,
					$input->getId(),
					$input->getMeta(),
					$output,
					$typeTag
				);
			}
		}

		$potionTypeRecipes = [];
		foreach($manager->getPotionTypeRecipes() as $recipe){
			try{
				$input = $converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor();
				$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
				if(!$input instanceof IntIdMetaItemDescriptor || !$ingredient instanceof IntIdMetaItemDescriptor){
					throw new AssumptionFailedError();
				}
				$output = $converter->coreItemStackToNet($recipe->getOutput());
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}

			$potionTypeRecipes[] = new ProtocolPotionTypeRecipe(
				$input->getId(),
				$input->getMeta(),
				$ingredient->getId(),
				$ingredient->getMeta(),
				$output->getId(),
				$output->getMeta()
			);
		}

		$potionContainerChangeRecipes = [];
		$itemTypeDictionary = $converter->getItemTypeDictionary();
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			$input = $itemTypeDictionary->fromStringId($recipe->getInputItemId());
			try{
				$ingredient = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
			}catch(\InvalidArgumentException|ItemTypeSerializeException){
				continue;
			}
			if(!$ingredient instanceof IntIdMetaItemDescriptor){
				throw new AssumptionFailedError();
			}
			$output = $itemTypeDictionary->fromStringId($recipe->getOutputItemId());
			$potionContainerChangeRecipes[] = new ProtocolPotionContainerChangeRecipe(
				$input,
				$ingredient->getId(),
				$output
			);
		}

		Timings::$craftingDataCacheRebuild->stopTiming();
		$s = PacketSerializer::encoder(Server::getInstance()->getPacketSerializerContext(TypeConverter::getInstance($this->protocolId)));
		CraftingDataPacket::create($recipesWithTypeIds, $potionTypeRecipes, $potionContainerChangeRecipes, [], true)
			->encode($s);
		return $s->getBuffer();
	}
}
