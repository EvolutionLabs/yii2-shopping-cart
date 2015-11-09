<?php

namespace yz\shoppingcart;

use Yii;
use yii\base\Component;
use yii\base\Event;
use yii\di\Instance;
use yii\web\Session;
use frontend\models\Cart;
use yii\db\Exception;
use common\models\ProductBulkOffer;


/**
 * Class ShoppingCart
 * @property CartPositionInterface[] $positions
 * @property int $count Total count of positions in the cart
 * @property int $cost Total cost of positions in the cart
 * @property bool $isEmpty Returns true if cart is empty
 * @property string $hash Returns hash (md5) of the current cart, that is uniq to the current combination
 * of positions, quantities and costs
 * @property string $serialized Get/set serialized content of the cart
 * @package \yz\shoppingcart
 */
class ShoppingCart extends Component
{
    /** Triggered on position put */
    const EVENT_POSITION_PUT = 'putPosition';
    /** Triggered on position update */
    const EVENT_POSITION_UPDATE = 'updatePosition';
    /** Triggered on after position remove */
    const EVENT_BEFORE_POSITION_REMOVE = 'removePosition';
    /** Triggered on any cart change: add, update, delete position */
    const EVENT_CART_CHANGE = 'cartChange';
    /** Triggered on after cart cost calculation */
    const EVENT_COST_CALCULATION = 'costCalculation';

    /**
     * model of the product in cart
     * @var \yii\base\Model
     */
    public $productModel;

    /**
     * If true (default) cart will be automatically stored in and loaded from session.
     * If false - you should do this manually with saveToSession and loadFromSession methods
     * @var bool
     */
    public $storeInSession = true;
    /**
     * Session component
     * @var string|Session
     */
    public $session = 'session';
    /**
     * Shopping cart ID to support multiple carts
     * @var string
     */
    public $cartId = __CLASS__;
    /**
     * @var array
     */
    public $discounts = [];
    /**
     * @var CartPositionInterface[]
     */
    protected $_positions = [];

    public function init()
    {
        if ($this->storeInSession)
            $this->loadFromSession();
    }

    /**
     * Loads cart from session
     */
    public function loadFromSession()
    {
        $this->session = Instance::ensure($this->session, Session::className());
        if (isset($this->session[$this->cartId]))
            $this->setSerialized($this->session[$this->cartId]);
    }

    /**
     * Saves cart to the session
     */
    public function saveToSession()
    {
        $this->session = Instance::ensure($this->session, Session::className());
        $this->session[$this->cartId] = $this->getSerialized();
    }

    /**
     * Sets cart from serialized string
     * @param string $serialized
     */
    public function setSerialized($serialized)
    {
        $this->_positions = unserialize($serialized);
    }

    /**
     * @param CartPositionInterface $position
     * @param int $quantity
     */
    public function put($position, $quantity = 1)
    {
        $id= $position->getId();
        Yii::trace($quantity);
        if (isset($this->_positions[$id])) {
            Yii::trace('found _position');
            $this->_positions[$id]->setQuantity($this->_positions[$id]->getQuantity() + $quantity);
        } else {
            $position->setQuantity($quantity);
            $this->_positions[$id] = $position;
            Yii::trace('not found _position');
            $this->_positions[$id] = $position;
        }
        $this->trigger(self::EVENT_POSITION_PUT, new CartActionEvent([
            'action' => CartActionEvent::ACTION_POSITION_PUT,
            'position' => $this->_positions[$id],
        ]));
        $this->trigger(self::EVENT_CART_CHANGE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_POSITION_PUT,
            'position' => $this->_positions[$id],
        ]));
        $qty = $this->_positions[$id]->getQuantity();
        $this->saveToDb($position, $qty, 1 );
        if ($this->storeInSession)
            $this->saveToSession();
    }

    /**
     * Returns cart positions as serialized items
     * @return string
     */
    public function getSerialized()
    {
        return serialize($this->_positions);
    }

    /**
     * @param CartPositionInterface $position
     * @param int                   $qty
     * @param int                   $status
     *
     * @throws Exception
     */
    public function saveToDb($position, $qty, $status = 1) {

        Yii::trace($qty);
        $website = Yii::$app->params['website'];

        if (Yii::$app->user->getIsGuest()) {
            $searchCriteria = ['id_product' => $position->id, 'session' => Yii::$app->session->id,
                 'website' => $website];
        } else {
            $searchCriteria = ['id_product' => $position->id, 'id_user' => Yii::$app->user->getId(),
                'website' => $website];
        }

        //  if we cant find a cart item we gonna assume it's a new record
        if (!$cartItem = Cart::findOne($searchCriteria)) {
            $cartItem = new Cart();
        }
        $price = $position->discountPrice;
        //TODO to remove and use offers
        $bulks = ProductBulkOffer::findAll(['id_product'=>$position->id, 'id_website'=>$website]);
        $lastMax=0;
        $lastMaxPercent=0;
        foreach ($bulks as $bulk) {
            $lastMax = $bulk->max;
            $lastMaxPercent = $bulk->percent;
            $dPrice = $price;
            if (is_null($position->discountPrice)) {
                $dPrice = $position->price;
            }
            if ($qty >= $bulk->min && $qty < $bulk->max) {
                $price = $dPrice - $dPrice * $bulk->percent;
            } elseif ($qty >= $lastMax) {
                $price = $dPrice - $dPrice * $lastMaxPercent;
            }
        }
        $cartItem->session = Yii::$app->session->id;
        $cartItem->id_user = Yii::$app->user->isGuest ? null : Yii::$app->user->getId();
        $cartItem->id_product = $position->id;
        //$cartItem->id_erp = $position->$erp;
        $cartItem->qty = $qty;
        $cartItem->price = $position->price;
        $cartItem->old_price = $position->oldPrice;
        //$cartItem->discounted_price = $position->discountPrice;
        $cartItem->discounted_price = $price;
        $cartItem->status = $status;
        $cartItem->website = $website;

        if (!$cartItem->save()) {
            throw new Exception('Could not save cart data', $cartItem->getErrors());
        }

    }

    /** save user to db on login for all lines where session = $session */
    /** @param int $session */
    public function saveUserToDB($session) { //problema : daca produsul se afla deja in cosul userului, trebuie facuta suma produselor.
        $model = Cart::findAll(['session'=>$session]);
        if(isset($model)) {
            foreach($model as $mods) {
                /** @var \frontend\models\Cart $model2 */
                $model2 = Cart::findOne(['id_user'=>Yii::$app->user->getId(),'id_product'=>$mods->id_product]);
                if($model2) { //if product is already in user cart
                    $model2->qty += $mods->qty;
                    $model2->status = 1;
                    $model2->save();
                    $mods->delete();
                }
                else {
                    $mods->id_user = Yii::$app->user->getId();
                    $mods->save();
                }
            }
        }
    }

    /**
     * @param CartPositionInterface $position
     * @param int $quantity
     */
    public function update($position, $quantity)
    {
        if ($quantity <= 0) {
            $this->remove($position);
            $this->saveToDb($position,$quantity, $status = 0);
            return;
        }
        $id= $position->getId();

        if (isset($this->_positions[$id])) {
            $this->_positions[$id]->setQuantity($quantity);
        } else {
            $position->setQuantity($quantity);
            $this->_positions[$id] = $position;
        }
        $this->trigger(self::EVENT_POSITION_UPDATE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_UPDATE,
            'position' => $this->_positions[$id],
        ]));
        $this->trigger(self::EVENT_CART_CHANGE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_UPDATE,
            'position' => $this->_positions[$id],
        ]));
        $this->saveToDb($position,$quantity, 1);
       
        if ($this->storeInSession)
            $this->saveToSession();
    }

    /**
     * @param CartPositionInterface $position
     * @param int $quantity
     */
    public function removeQuantity($position, $quantity)
    {
        $id= $position->getId();

        if (!\Yii::$app->user->isGuest) {
            $model = Cart::findOne(['id_product'=>$id,'id_user'=>Yii::$app->user->getId(), 'status'=>1]);
        }
        else {
            $model = Cart::findOne(['id_product'=>$id,'id_user'=>null,'session'=>Yii::$app->session->getId(), 'status'=>1]);
        }
        if (!isset($this->_positions[$id])) {
            $this->_positions[$id] = $position;
        }
        $quantity = $model->qty - $quantity;
        if ($quantity <= 0) {
            $this->remove($position);
            return;
        }
        $this->_positions[$id]->setQuantity($quantity);
        $this->trigger(self::EVENT_POSITION_UPDATE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_UPDATE,
            'position' => $this->_positions[$id],
        ]));
        $this->trigger(self::EVENT_CART_CHANGE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_UPDATE,
            'position' => $this->_positions[$id],
        ]));
        $this->saveToDb($position,$quantity, 1);
        $this->saveToSession();
    }

    /**
     * Removes position from the cart
     * @param CartPositionInterface $position
     */
    public function remove($position)
    {
        $id = $position->getId();
        if(array_key_exists($id, $this->_positions)) {
            $this->trigger(self::EVENT_BEFORE_POSITION_REMOVE, new Event([
                'data' => $this->_positions[$id],
            ]));
            $this->trigger(self::EVENT_CART_CHANGE, new Event([
                'data' => ['action' => 'remove', 'position' => $this->_positions[$id]],
            ]));
            unset($this->_positions[$id]);
            $this->saveToSession();
        }
        $this->saveToDb($position,0,0);
    }

    /**
     * Remove all positions
     */
    public function removeAll()
    {
        // $this->loadFromSession();
        $products = $this->getPositions();
        foreach($products as $p) {
            $this->saveToDb($p, 0, 0);
        }
        $this->_positions = [];
        $this->trigger(self::EVENT_CART_CHANGE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_REMOVE_ALL,
        ]));
        if ($this->storeInSession)
            $this->saveToSession();

        // remove all from cart for current user
        $models = Cart::findAll(['id_user'=>Yii::$app->user->getId()]);
        $productModel = $this->productModel;
        foreach ($models as $model) {
            $model2 = $productModel::findOne([$productModel::tableName().'.id' => $model->id]);
            if(!is_null($model2)) {
                $this->saveToDb($model2, 0, 0);
            }
        }
    }

    /**
     * Returns position by it's id. Null is returned if position was not found
     * @param string $id
     * @return CartPositionInterface|null
     */
    public function getPositionById($id)
    {
        if ($this->hasPosition($id))
            return $this->_positions[$id];
        else
            return null;
    }

    /**
     * Checks whether cart position exists or not
     * @param string $id
     * @return bool
     */
    public function hasPosition($id)
    {
        return isset($this->_positions[$id]);
    }

    /**
     * @return CartPositionInterface[]
     */
    public function getPositions()
    {
        if (!\Yii::$app->user->isGuest) {
            $model2 = (new Cart())->findAll(['id_user'=>Yii::$app->user->getId(), 'status'=>1, 'website'=>Yii::$app->params['website']]);
        }
        else {
            $model2 = (new Cart())->findAll(['id_user'=>null,'session'=>Yii::$app->session->getId(), 'status'=>1, 'website'=>Yii::$app->params['website']]);
        }
        $prod= array();
        $productModel = $this->productModel;
        foreach($model2 as $model) {
	        $obs = $productModel::find()->where([$productModel::tableName().'.id' => $model->id_product])->one();
            if (!is_null($obs)){
                $obs->quantity = $model->qty;
                $obs->discountPrice = $model->discounted_price;
                $prod[$model->id] = $obs;
            }
        }
        return $prod;
    }

    /**
     * @param CartPositionInterface[] $positions
     */
    public function setPositions($positions)
    {
        $this->_positions = array_filter($positions, function (CartPositionInterface $position) {
            return $position->quantity > 0;
        });
        $this->trigger(self::EVENT_CART_CHANGE, new CartActionEvent([
            'action' => CartActionEvent::ACTION_SET_POSITIONS,
        ]));
        if ($this->storeInSession)
            $this->saveToSession();
    }

    /**
     * Returns true if cart is empty
     * @return bool
     */
    public function getIsEmpty()
    {
        return count($this->_positions) == 0;
    }

    /**
     * @return int
     */
    public function getCount()
    {
        $count = 0;
        if (!\Yii::$app->user->isGuest) {
            $models = Cart::findAll(['id_user' => Yii::$app->user->getId(), 'status' => 1, 'website'=>Yii::$app->params['website']]);
            foreach ($models as $model) {
                $count += $model->qty;
            }
            return $count;
        } else {
            foreach ($this->_positions as $position)
                $count += $position->getQuantity();
            return $count;
        }
    }

    /**
     * Return full cart cost as a sum of the individual positions costs
     * @param $withDiscount
     * @return int
     */
    //TODO ce facem in cazul in care produsul a fost adaugat in cos, si intre timp a fost modificat???
    public function getCost($withDiscount = false)
    {
        $totalCost=0;
        $totalNet = 0;
        $totalDiscount=0;
        $totalCostNoDiscount = 0;
        $models = $this->getPositions() ;
        foreach ($models as $model) {
            $price = $model->price;
            $vat = $model->vat;

            if (!is_null($model->discountPrice)){
                $price = $model->discountPrice;
                $totalDiscount += ($model->price - $model->discountPrice)*$model->quantity;
            }
            $totalCost += $price * $model->quantity;
            if($model['offer'] && $model['offer']['discountPrice'] && $model['offer']['quantity']) {
                $totalDiscount += ($model->price - $model['offer']['discountPrice']) * $model['offer']['quantity'];
                $totalCost -= ($model['offer']['discountPrice'] * $model['offer']['quantity']);
            }
            $totalCostNoDiscount +=$model->price *$model->quantity;

            if ($vat > 0 && $vat < 1)
                $vat = 100 * $vat;
            $totalNet += ( 100 * ($price) / (100 + ($vat)) ) * $model->quantity;
        }
        return [
            'totalCost'=>$totalCost,
            'totalDiscount'=>$totalDiscount,
            'totalCostNoDiscount'=>$totalCostNoDiscount,
            'totalNet'=>$totalNet,
        ];
    }

    /**
     * Returns hash (md5) of the current cart, that is unique to the current combination
     * of positions, quantities and costs. This helps us fast compare if two carts are the same, or not, also
     * we can detect if cart is changed (comparing hash to the one's saved somewhere)
     * @return string
     */
    public function getHash()
    {
        $data = [];
        foreach ($this->positions as $position) {
            $data[] = [$position->getId(), $position->getQuantity(), $position->getPrice()];
        }
        return md5(serialize($data));
    }
}
