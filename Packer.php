<?php
/**
 * Box packing (3D bin packing, knapsack problem)
 * @package BoxPacker
 * @author Doug Wright
 */
  namespace DVDoug\BoxPacker;

  use Psr\Log\LoggerAwareInterface;
  use Psr\Log\LoggerAwareTrait;
  use Psr\Log\LogLevel;
  use Psr\Log\NullLogger;

  /**
   * Actual packer
   * @author Doug Wright
   * @package BoxPacker
   */
  class Packer implements LoggerAwareInterface {
    use LoggerAwareTrait;

    /**
     * List of items to be packed
     * @var ItemList
     */
    protected $items;

    /**
     * List of box sizes available to pack items into
     * @var BoxList
     */
    protected $boxes;

    /**
     * Constructor
     */
    public function __construct() {
      $this->items = new ItemList();
      $this->boxes = new BoxList();

      $this->logger = new NullLogger();
    }

    /**
     * Add item to be packed
     * @param Item $aItem
     * @param int  $aQty
     */
    public function addItem(Item $aItem, $aQty = 1) {
      for ($i = 0; $i < $aQty; $i++) {
        $this->items->insert($aItem);
      }
      $this->logger->log(LogLevel::INFO, "added {$aQty} x {$aItem->getDescription()}");
    }

    /**
     * Set a list of items all at once
     * @param \Traversable $aItems
     */
    public function setItems($aItems) {
      if ($aItems instanceof ItemList) {
        $this->items = clone $aItems;
      }
      else if (is_array($aItems)) {
        $this->items = new ItemList();
        foreach ($aItems as $item) {
          $this->items->insert($item);
        }
      }
      else {
        throw new \RuntimeException('Not a valid list of items');
      }
    }

    /**
     * Add box size
     * @param Box $aBox
     */
    public function addBox(Box $aBox) {
      $this->boxes->insert($aBox);
      $this->logger->log(LogLevel::INFO, "added box {$aBox->getReference()}");
    }

    /**
     * Add a pre-prepared set of boxes all at once
     * @param BoxList $aBoxList
     */
    public function setBoxes(BoxList $aBoxList) {
      $this->boxes = clone $aBoxList;
    }

    /**
     * Pack items into boxes
     *
     * @throws \RuntimeException
     * @return PackedBoxList
     */
    public function pack() {
      $packedBoxes = $this->doVolumePacking();

      //If we have multiple boxes, try and optimise/even-out weight distribution
      if ($packedBoxes->count() > 1) {
        $packedBoxes = $this->redistributeWeight($packedBoxes);
      }

      $this->logger->log(LogLevel::INFO, "packing completed, {$packedBoxes->count()} boxes");
      return $packedBoxes;
    }

    /**
     * Pack items into boxes using the principle of largest volume item first
     *
     * @throws \RuntimeException
     * @return PackedBoxList
     */
    public function doVolumePacking() {

      $packedBoxes = new PackedBoxList;

      //Keep going until everything packed
      while ($this->items->count()) {
        $boxesToEvaluate = clone $this->boxes;
        $packedBoxesIteration = new PackedBoxList;

        //Loop through boxes starting with smallest, see what happens
        while (!$boxesToEvaluate->isEmpty()) {
          $box = $boxesToEvaluate->extract();
          $packedItems = $this->packBox($box, clone $this->items);
          if ($packedItems->count()) {
            $packedBoxesIteration->insert(new PackedBox($box, $packedItems));

            //Have we found a single box that contains everything?
            if ($packedItems->count() === $this->items->count()) {
              break;
            }
          }
        }

        //Check iteration was productive
        if ($packedBoxesIteration->isEmpty()) {
          throw new \RuntimeException('Item ' . $this->items->top()->getDescription() . ' is too large to fit into any box');
        }

        //Find best box of iteration, and remove packed items from unpacked list
        $bestBox = $packedBoxesIteration->top();
        for ($i = 0; $i < $bestBox->getItems()->count(); $i++) {
          $this->items->extract();
        }
        $packedBoxes->insert($bestBox);

      }

      return $packedBoxes;
    }

    /**
     * Given a solution set of packed boxes, repack them to achieve optimum weight distribution
     *
     * @param PackedBoxList $aPackedBoxes
     * @return PackedBoxList
     */
    public function redistributeWeight(PackedBoxList $aPackedBoxes) {
      $targetWeight = $aPackedBoxes->getMeanWeight();
      $this->logger->log(LogLevel::DEBUG,  "repacking for weight distribution, weight variance {$aPackedBoxes->getWeightVariance()}, target weight {$targetWeight}");

      $packedBoxes = new PackedBoxList;

      $overWeightBoxes = [];
      $underWeightBoxes = [];
      foreach ($aPackedBoxes as $packedBox) {
        $boxWeight = $packedBox->getWeight();
        if ($boxWeight > $targetWeight) {
          $overWeightBoxes[] = $packedBox;
        }
        else if ($boxWeight < $targetWeight) {
          $underWeightBoxes[] = $packedBox;
        }
        else {
          $packedBoxes->insert($packedBox); //target weight, so we'll keep these
        }
      }

      do { //Keep moving items from most overweight box to most underweight box
        $tryRepack = false;
        $this->logger->log(LogLevel::DEBUG,  'boxes under/over target: ' . count($underWeightBoxes) . '/' . count($overWeightBoxes));

        foreach ($underWeightBoxes as $u => $underWeightBox) {
          foreach ($overWeightBoxes as $o => $overWeightBox) {
            $overWeightBoxItems = $overWeightBox->getItems()->asArray();

            //For each item in the heavier box, try and move it to the lighter one
            foreach ($overWeightBoxItems as $oi => $overWeightBoxItem) {
              $oldDifferenceFromTarget = abs($targetWeight - $underWeightBox->getWeight());
              $newDifferenceFromTarget = abs($targetWeight - $underWeightBox->getWeight() + $overWeightBoxItem->getWeight());

              if ($newDifferenceFromTarget > $oldDifferenceFromTarget) {
                continue; //skip if moving this item would hinder rather than help weight distribution
              }

              $newItemsForLighterBox = clone $underWeightBox->getItems();
              $newItemsForLighterBox->insert($overWeightBoxItem);

              $newLighterBoxPacker = new Packer(); //we may need a bigger box
              $newLighterBoxPacker->setBoxes($this->boxes);
              $newLighterBoxPacker->setItems($newItemsForLighterBox);
              $newLighterBox = $newLighterBoxPacker->doVolumePacking()->extract();

              if ($newLighterBox->getItems()->count() === $newItemsForLighterBox->count()) { //new item fits
                unset($overWeightBoxItems[$oi]); //now packed in different box

                $newHeavierBoxPacker = new Packer(); //we may be able to use a smaller box
                $newHeavierBoxPacker->setBoxes($this->boxes);
                $newHeavierBoxPacker->setItems($overWeightBoxItems);

                $overWeightBoxes[$o] = $newHeavierBoxPacker->doVolumePacking()->extract();
                $underWeightBoxes[$u] = $newLighterBox;

                $tryRepack = true; //we did some work, so see if we can do even better
                usort($overWeightBoxes, [$packedBoxes, 'reverseCompare']);
                usort($underWeightBoxes, [$packedBoxes, 'reverseCompare']);
                break 3;
              }
            }
          }
        }
      } while ($tryRepack);

      //Combine back into a single list
      $packedBoxes->insertFromArray($overWeightBoxes);
      $packedBoxes->insertFromArray($underWeightBoxes);

      return $packedBoxes;
    }


    /**
     * Pack as many items as possible into specific given box
     * @param Box      $aBox
     * @param ItemList $aItems
     * @return ItemList items packed into box
     */
    public function packBox(Box $aBox, ItemList $aItems) {
      $this->logger->log(LogLevel::DEBUG,  "evaluating box {$aBox->getReference()}");

      $packedItems = new ItemList();
      $remainingDepth = $aBox->getInnerDepth();
      $remainingWeight = $aBox->getMaxWeight() - $aBox->getEmptyWeight();

      // Normalise box width/length so that length is always the longer side
      $remainingWidth = min($aBox->getInnerWidth(), $aBox->getInnerLength());
      $remainingLength = max($aBox->getInnerWidth(), $aBox->getInnerLength());

      $layerWidth = $layerLength = $layerDepth = 0;

      while(!$aItems->isEmpty()) {

        // Get the next item to be packed, which will be the largest remaining item
        $itemToPack = $aItems->top();

        // Finish packing this box if not enough remaining depth/weight in the box
        if ($itemToPack->getDepth() > $remainingDepth || $itemToPack->getWeight() > $remainingWeight) {
          break;
        }

        $this->logger->log(LogLevel::DEBUG,  "evaluating item {$itemToPack->getDescription()} ({$itemToPack->getWidth()} {$itemToPack->getLength()} {$itemToPack->getDepth()})");
        $this->logger->log(LogLevel::DEBUG,  "remaining width :{$remainingWidth}, length: {$remainingLength}, depth: {$remainingDepth}");
        $this->logger->log(LogLevel::DEBUG,  "layerWidth: {$layerWidth}, layerLength: {$layerLength}, layerDepth: {$layerDepth}");

        // Normalise item width/length so that length is always the longer side
        $itemWidth = min($itemToPack->getWidth(), $itemToPack->getLength());
        $itemLength = max($itemToPack->getWidth(), $itemToPack->getLength());

        // Calculations for fitting the item in the current orientation
        $sameGapWidth = $remainingWidth - $itemWidth;
        $sameGapLength = $remainingLength - $itemLength;
        $sameGapMin = min($sameGapWidth, $sameGapLength);
        $sameFits = $sameGapMin >= 0;

        // Calculations for fitting the item in the rotated orientation
        $rotatedGapWidth = $remainingWidth - $itemLength;
        $rotatedGapLength = $remainingLength - $itemWidth;
        $rotatedGapMin = min($rotatedGapWidth, $rotatedGapLength);
        $rotatedFits = $rotatedGapMin >= 0;

        $this->logger->log(LogLevel::DEBUG,  "fits same gap: {$sameGapMin}");
        $this->logger->log(LogLevel::DEBUG,  "fits rotated gap: {$rotatedGapMin}");

        // Item fits this layer
        if ($sameFits or $rotatedFits) {

          $packedItems->insert($aItems->extract());
          $remainingWeight -= $itemToPack->getWeight();

          // Current orientation is better if it has a smaller min gap, whilst fitting, or the item doesn't fit rotated
          $sameBetter = (($sameFits and $sameGapMin <= $rotatedGapMin) or !$rotatedFits);

          // Fits better unrotated
          if ($sameBetter) {
            $this->logger->log(LogLevel::DEBUG,  "fits (better) unrotated");

            // Remove smallest gap from remaining area
            if ($sameGapWidth <= $sameGapLength) {
              $remainingLength -= $itemLength;
            } else {
              $remainingWidth -= $itemWidth;
            }


            $layerWidth += $itemWidth;
            $layerLength += $itemLength;
          }

          // Fits better rotated
          else {
            $this->logger->log(LogLevel::DEBUG,  "fits (better) rotated");

            if ($rotatedGapWidth <= $rotatedGapLength) {
              $remainingLength -= $itemWidth;
            } else {
              $remainingWidth -= $itemLength;
            }

            $layerWidth += $itemLength;
            $layerLength += $itemWidth;
          }

          $layerDepth = max($layerDepth, $itemToPack->getDepth());
        }

        // Item does not fit the current layer
        else {
          // If it was an empty layer, item does not fit in box
          if (!$layerWidth) {
            $this->logger->log(LogLevel::DEBUG,  "doesn't fit on layer even when empty");
            break;
          }

          // Set things up for trying a new layer
          $remainingWidth = $aBox->getInnerWidth();
          $remainingLength = $aBox->getInnerLength();
          $remainingDepth -= $layerDepth;

          $layerWidth = $layerLength = $layerDepth = 0;
          $this->logger->log(LogLevel::DEBUG,  "doesn't fit, so starting next vertical layer");
        }
      }

      $this->logger->log(LogLevel::DEBUG,  "done with this box");

      return $packedItems;
    }
  }
