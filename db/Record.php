<?php
namespace rap\db;

use rap\ioc\Ioc;
use rap\storage\Storage;

/**
 * 南京灵衍信息科技有限公司
 * User: jinghao@duohuo.net
 * Date: 17/9/22
 * Time: 上午10:28
 */
class Record {

    /**
     * 获取表名,包含 as 时会添加上as  如 User::table('u') 返回user u
     *
     * @param string $as
     *
     * @return string
     */
    public static function table($as = "") {
        $model = get_called_class();
        /* @var $model Record */
        $model = new $model;
        $table = $model->getTable();
        if ($as) {
            $table .= " " . $as;
        }
        return $table;
    }

    /**
     * 获取数据库所有字段名
     * @return array
     */
    public static function fields() {
        $model = get_called_class();
        /* @var $model Record */
        $model = new $model;
        return array_keys($model->getFields());
    }

    /**
     * 存在数据库里的数据
     * @var array
     */
    private $_db_data = [];

    /**
     * 供find 使用的缓存的可以  如['user_id,open_id','cat_id,good_id']
     * 同一组用,隔开 整体是数组
     * @return array
     */
    public function cacheKeys() {
        return [];
    }

    /**
     * @return Connection
     */
    protected function getConnection() {
        return Ioc::get(Connection::class);
    }

    /**
     * 表明是否替换
     * @var bool
     */
    private $to_updata = false;

    /**
     * 表明时候是替换
     *
     * @param bool $update
     */
    public function isUpdate($update = true) {
        $this->to_updata = $update;
    }

    /**
     * 数据库字段组装对象
     *
     * @param $items
     */
    public function fromDbData($items) {
        $this->to_updata = true;
        $_fields = $this->getFields();
        $this->_db_data = $items;
        foreach ($items as $item => $value) {
            $type = $_fields[ $item ];
            if (is_array($type)) {
                $type = $type[ 'type' ];
            }
            if ($type == 'json') {
                $value = json_decode($value, true);
            } else if ($type == 'int') {
                if ($value !== null) {
                    $value = (int)$value;
                }
            } else if ($type == 'boolean') {
                $value = (int)$value;
                $value = $value == 1 ? true : false;
            } else if ($type == 'float') {
                $value = (float)$value;
            } else if ($type == 'time') {
                $time = (int)$value;
                if ($time . "" == $value . "") {
                    $value = date("Y-m-d H:i:s", $this->_db_data[ $item ]);
                }
            } else if ($type == 'date') {
                $time = (int)$value;
                if ($time . "" == $value) {
                    $value = date("Y-m-d", $this->_db_data[ $item ]);
                }
            } else if ($type == 'attach' || $type == 'attach_i') {
                $attach = json_decode($value, true);
                if (count($attach) > 0) {
                    $attach = $attach[ 0 ];
                } else {
                    continue;
                }
                $url = $attach[ 'url' ];
                $image_type = 'default';
                if (key_exists('type', $attach)) {
                    $image_type = $attach[ 'type' ];
                }
                $domian = Storage::getStorage($image_type)->getDomain();
                if (!(strpos($url, 'http') === 0) && $url) {
                    $attach[ 'url' ] = $domian . $url;
                }
                if ($type == 'attach') {
                    $value = $attach;
                } else {
                    $value = $attach[ 'url' ];
                }
            } else if ($type == 'attach_s') {
                $value = json_decode($value, true);
                $values = [];

                foreach ($value as $v) {
                    $type = 'default';
                    if (key_exists('type', $v)) {
                        $type = $v[ 'type' ];
                    }
                    $domian = Storage::getStorage($type)->getDomain();
                    $url = $v[ 'url' ];
                    if (!(strpos($url, 'http') === 0)) {
                        $v[ 'url' ] = $domian . $url;
                    }
                    $values[] = $v;
                }
                $value = $values;
            }
            $this->$item = $value;
        }
    }

    /**
     * 保存 如果主键存在就更新,否则插入
     * 如果数据库中没有设置自增主键的话也会进行判定
     */
    public function save() {
        $pk = $this->getPkField();
        //主键是id
        if (($pk == 'id' && $this->$pk) || $this->to_updata) {
            $this->update();
        } else {
            if ($this->$pk) {
                $this->checkHas();
            }
            if ($this->to_updata) {
                $this->update();
            } else {
                $this->insert();
            }
        }
    }


    /**
     * 获取保存的对象
     * @return array
     */
    private function getDBData() {
        $data = [];
        $fields = $this->getFields();
        foreach ($fields as $field => $type) {
            $value = $this->$field;
            if ($value === null) {
                continue;
            }
            $oldValue = $this->_db_data[ $field ];
            if (is_array($type)) {
                $type = $type[ 'type' ];
            }
            if ($type == 'json' && !is_string($value)) {
                $value = json_encode($value);
            }
            //值没有变不保存
            if ($value == $oldValue && $oldValue != null) {
                continue;
            }

            if ($value === 'null') {
                $value = null;
                $this->$field = null;
            } else if ($type == 'int') {
                $value = (int)$value;
            } else if ($type == 'float') {
                $value = (float)$value;
            } else if ($type == 'time' || $type == 'date') {
                $time = (int)$value;
                if ($time . "" != $value) {
                    $value = strtotime($value);
                }
            } else if ($type == 'attach_s') {
                if (is_string($value)) {
                    $attach = [['url' => $value]];
                } else {
                    $attach = $value;
                }
                $values = [];
                foreach ($attach as $item) {
                    $type = 'default';
                    if (key_exists('type', $item)) {
                        $type = $item[ 'type' ];
                    }
                    $url = $item[ 'url' ];
                    $domian = Storage::getStorage($type)->getDomain();
                    if ($domian) {
                        $url = str_replace($domian, "", $url);
                    }
                    $item[ 'url' ] = $url;
                    $values[] = $item;
                }
                $value = json_encode($values);
                if ($value == $oldValue && $oldValue != null) {
                    continue;
                }
            } else if ($type == 'attach' || $type == 'attach_i') {
                if (is_string($value)) {
                    $item = ['url' => $value];
                } else {
                    $item = $value;
                }
                $type = 'default';
                if (key_exists('type', $item)) {
                    $type = $item[ 'type' ];
                }
                $url = $item[ 'url' ];
                $domian = Storage::getStorage($type)->getDomain();
                if ($domian) {
                    $url = str_replace($domian, "", $url);
                }
                $item[ 'url' ] = $url;
                $item = [$item];
                $value = json_encode($item);
                if ($value == $oldValue && $oldValue != null) {
                    continue;
                }
            }
            $data[ $field ] = $value;
        }
        return $data;
    }

    /**
     * 插入
     */
    public function insert() {
        $pk = $this->getPkField();
        $data = $this->getDBData();
        $create_time = 'create_time';
        if (property_exists(get_called_class(), $create_time) && !$data[ $create_time ]) {
            $data[ $create_time ] = time() - 10;
        }
        $pk_value = DB::insert($this->getTable(), $data, $this->getConnection());
        if (!$this->$pk) {
            $this->$pk = $pk_value;
        }
        //数据放入缓存防止立马拿,由于主从库延迟拿不到
        $data[ $pk ] = $pk_value;
        /* @var $db_cache DBCache */
        $db_cache = Ioc::get(DBCache::class);
        $db_cache->recordCacheSave(get_called_class(), $pk_value, $data);
    }

    /**
     * 更新
     */
    public function update() {
        $model = get_called_class();
        $pk = $this->getPkField();
        $where[ $pk ] = $this->$pk;
        $data = $this->getDBData();
        if (!$data) {
            return;
        }
        $update_time = 'update_time';
        if (property_exists(get_called_class(), $update_time)) {
            $data[ $update_time ] = time();
        }
        DB::update($this->getTable(), $data, $where, $this->getConnection());
        //删除缓存
        /* @var $db_cache DBCache */
        $db_cache = Ioc::get(DBCache::class);
        $db_cache->recordWhereCacheDel($model, $this->cacheKeys(), $this->_db_data);
        $db_cache->recordCacheDel($model, $this->$pk);
    }


    /**
     * 删除当前对象
     * 如果有 delete_time字段 默认是设置为当前时间
     *
     * @param bool $force 是否强制
     */
    public function delete($force = false) {
        $model = get_called_class();
        $pk = $this->getPkField();
        $id = $this->$pk;
        if (isset($id)) {
            $where[ $pk ] = $id;
            $delete_time = "delete_time";
            if (property_exists($model, $delete_time)) {
                if (!$force) {
                    $this->$delete_time = time();
                    $this->update();
                    return;
                }
            }
            DB::delete($this->getTable(), null, $this->getConnection())->where($pk, $id)->excuse();
        }
        //删除缓存
        /* @var $db_cache DBCache */
        $db_cache = Ioc::get(DBCache::class);
        $db_cache->recordWhereCacheDel($model, $this->cacheKeys(), $this->_db_data);
        $db_cache->recordCacheDel($model, $id);
    }

    /**
     * 查找一个对象
     *
     * @param array $where ['a'=>'a','b'=>'b']
     *
     * @return $this;
     */
    public static function find(array $where) {
        $model = get_called_class();
        /* @var $t Record */
        $t = new $model;
        /* @var $db_cache DBCache */
        $db_cache = Ioc::get(DBCache::class);
        $data = $db_cache->recordWhereCache($model, $where);
        if ($data) {
            return $data;
        }
        /* @var $data Record */
        $data = DB::select($t->getTable(), $t->getConnection())->where($where)->setRecord($model)->find();
        $db_cache->recordWhereCacheSave($model, $where, $data->_db_data);
        return $data;
    }

    /**
     * 查找一个对象,如果不存在就创建
     *
     * @param array $where ['a'=>'a','b'=>'b']
     *
     * @return $this
     */
    public static function findCreate(array $where) {
        $item = self::find($where);
        if (!$item) {
            $model = get_called_class();
            /* @var $item Record */
            $item = new $model;
            $item->fromArray($where);
        }
        return $item;
    }


    /**
     * 静态删除  destroy方法不管delete_time字段
     *
     * @param string|int $id
     */
    public static function destroy($id) {
        $model = get_called_class();
        /* @var $model Record */
        $model = new $model;
        $pk = $model->getPkField();
        $model->$pk = $id;
        $model->delete();
    }

    /**
     * 根据主键获取对象
     *
     * @param string|int $id
     * @param bool       $cache 是否使用缓存
     *
     * @return $this;
     */
    public static function get($id, $cache = true) {
        if (!$id) {
            return null;
        }
        $model = get_called_class();
        $db_cache = null;
        if ($cache) {
            /* @var $db_cache DBCache */
            $db_cache = Ioc::get(DBCache::class);
            $data = $db_cache->recordCache($model, $id);
            if ($data) {
                return $data;
            }
        }
        /* @var $model Record */
        $model = new $model;
        $pk = $model->getPkField();
        $where[ $pk ] = $id;
        $data = $model::find($where);
        if ($cache && $data) {
            $db_cache->recordCacheSave(get_called_class(), $id, $data->_db_data);
        }
        return $data;
    }

    /**
     * 查询对象并获取对象的事务锁
     *
     * @param string|int $id
     *
     * @return $this
     */
    public static function getLock($id) {

        $model = get_called_class();
        /* @var $t Record */
        $t = new $model;
        $data = DB::select($t->getTable(), $t->getConnection())
                  ->where($t->getPkField(), $id)
                  ->lock()
                  ->setRecord($model)
                  ->find();
        return $data;
    }

    /**
     * 检索
     *
     * @param string $fields 多个字段用,隔开
     * @param bool   $contain
     *
     * @return Select
     */
    public static function select($fields = '', $contain = true) {
        $model = get_called_class();
        preg_match_all('/([A-Z]{1})/', substr($model, strrpos($model, '\\') + 1), $matches);
        $as = strtolower(implode("", $matches[ 0 ]));
        if ($as == 'or') {
            $as = 'o_r';
        }
        if ($as == 'and') {
            $as = 'a_n_d';
        }
        /* @var $model Record */
        $model = new $model;
        $select = DB::select($model->getTable() . " " . $as, $model->getConnection())->setRecord(get_called_class());
        if ($fields) {
            if (!$contain) {
                $fieldAll = $model->getFields();
                $fields = explode(",", $fields);
                $need = [];
                foreach ($fieldAll as $field => $value) {
                    if (!in_array($field, $fields)) {
                        $need[] = $field;
                    }
                }
                $fields = implode(",", $need);
            }
            $select->fields($fields);
        }
        return $select;
    }


    /**
     * 获取字段
     * @return mixed
     */
    public function getFields() {
        $connection = $this->getConnection();
        return $connection->getFields($this->getTable());
    }

    /**
     * 获取主键 默认
     * @return mixed
     */
    public function getPkField() {
        return "id";
    }

    /**
     * 获取表
     * @return string
     */
    public function getTable() {
        $table = get_called_class();
        return $table;
    }

    /**
     * 字符串转对象
     *
     * @param $str
     */
    public function fromString($str) {
        $array = json_decode($str, true);
        $this::fromArray($array);
    }

    /**
     * 数组转对象
     *
     * @param $array
     */
    public function fromArray($array) {
        foreach ($array as $key => $value) {
            if (isset($value)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * 转化为数组
     *
     * @param string $fields  ['id,name']
     * @param bool   $contain 如果为 false就反向选取字段
     *
     * @return array|mixed|string
     */
    public function toArray($fields, $contain = true) {
        $keys = explode(',', $fields);
        $data = [];
        if ($contain) {
            foreach ($keys as $key) {
                $data[ $key ] = $this->$key;
            }
        } else {
            $data = json_encode($this);
            $data = json_decode($data, true);
            foreach ($data as $key => $value) {
                if (in_array($key, $keys)) {
                    unset($data[ $key ]);
                }
            }
        }
        return $data;
    }

    /**
     * 常量类型替换
     */
    public function renderConst() {
        $fields = $this->getFields();
        foreach ($fields as $field => $type) {
            if (is_array($type)) {
                $con = $type[ 'const' ];
                $value = $this->$field;
                if (is_int($value)) {
                    $value -= 1;
                }
                $field_show = $field . "_show";
                $this->$field_show = $con[ $value ];
            }
        }
    }

    /**
     * 同时删除对象关联的附件
     */
    public function deleteAttach() {
        $model = get_called_class();
        /* @var $model Record */
        $model = new $model;
        $model = $model::get($this->getPk());
        $fields = $this->getFields();
        foreach ($fields as $field) {
            if ($field == 'attach' || $field == 'attach_s') {
                $value = $model->_db_data[ $field ];
                if (!$value) {
                    continue;
                }
                $attach_s = json_encode($value, true);
                foreach ($attach_s as $attach) {
                    $type = $attach[ 'type' ];
                    $url = $attach[ 'url' ];
                    if (!$type) {
                        $type = 'default';
                    }
                    Storage::getStorage($type)->delete($url);
                }
            }
        }
    }

    /**
     * 获取主键的值
     * @return string|int
     */
    public function getPk() {
        $pk = $this->getPkField();
        return $this->$pk;
    }

    /**
     * 检查数据库是否有
     * @return bool
     */
    public function checkHas() {
        /* @var $model Record */
        $model = get_called_class();
        $model = $model::get($this->getPk());
        if ($model) {
            $this->isUpdate(true);
            return true;
        }
        return false;
    }


}