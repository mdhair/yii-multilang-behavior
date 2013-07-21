<?php
/* 
      Multilanguage Behavior
      
      Usage
      1. Add to the Post model:
    
            public function behaviors(){
                  return array(
                        'multilang' => array(
                              'class' => 'ext.behaviors.multilang.MultilangBehavior',
                              'translationClass' => 'PostLang',
                              'defaultLanguage' => 'uk',
                              'languages' => array(en, fr, ru, uk),
                              // Поля вспомогательной таблицы, хранящие переводы
                              'attributes' => array('title', 'text'),
                        ),
                  ),
            }
    
    
      2. CGridView (view file):
    
            $this->widget('zii.widgets.grid.CGridView', array(  
                  'id'=>'post-grid',
                  'dataProvider'=>$model->search(),
                  'columns'=>array(
                        ...
                        'title',
                        'text'
                        ...
                  ),
            ));
    
    
      3. If you need a filter for translatable fields, edit the search method:
    
            public function search()
            {
                  $criteria=new CDbCriteria;
                  
                  // Need to add table alias 't.'
                  $criteria->compare('t.id',$this->id);
                  ...
                  
                  // Modify the search criteria. Добавляет возможность фильтрации по переводимым атрибутам
                  $criteria = $this->multilang->modifySearchCriteria($criteria);
                  
                  return new CActiveDataProvider($this, array(
                      'criteria'=>$criteria,
                  ));
            }
            
*/

    class MultilangBehavior extends CActiveRecordBehavior
    {
        public $translationClass;
        public $defaultLanguage;
        public $attributes;
        public $languages;

        private $dynamicAttributes;
        private $currentLanguage;

        /**
         * @param CActiveRecord $owner
         */
        public function attach($owner)
        {
            parent::attach($owner);

            $this->currentLanguage = Yii::app()->language;

            $attributes = array();
            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {
                    $attributes[] = $attribute.'_'.$lang;
                }
            }

            $validators = $owner->getValidatorList();
            $validator = CValidator::createValidator('required', $owner, $attributes);
            $validators->add($validator);

            $validator = CValidator::createValidator('safe', $owner, $this->attributes);
            $validators->add($validator);

            $owner->getMetaData()->addRelation("contents", array(
                CActiveRecord::HAS_MANY, $this->translationClass, 'owner_id',
            ));
        }

        public function modifySearchCriteria(CDbCriteria $criteria)
        {
            /* @var $owner CActiveRecord */
            $owner = $this->getOwner();

            $criteria->mergeWith(array(
                'with' => array(
                    'contents' => array(
                        'condition' => 'contents.lang_id = :lang',
                        'params' => array(':lang' => $this->currentLanguage),
                        'together' => true
                    )
                )
            ));

            foreach ($this->attributes as $attribute) {
                if (!empty($owner->$attribute)) {
                    $criteria->compare($attribute, $owner->$attribute, true);
                }
            }

            return $criteria;
        }

        public function afterFind($event)
        {
            /* @var $owner CActiveRecord */
            $owner = $this->getOwner();

            $owner->getDbCriteria()->mergeWith(array(
                'with' => array("contents")
            ));

            foreach ($this->languages as $lang) {
                foreach ($this->attributes as $attribute) {
                    foreach ($owner->contents as $content) {
                        if ($content->lang_id == $lang) {
                            $this->{$attribute.'_'.$lang} = $content->{$attribute};
                        }

                        if ($content->lang_id == $this->currentLanguage) {
                            $this->{$attribute} = $content->{$attribute};
                        }
                    }

                    if (!isset($this->{$attribute.'_'.$lang})) {
                        $this->{$attribute.'_'.$lang} = '';
                    }
                }
            }
        }

        public function afterSave($event)
        {
            /* @var $owner CActiveRecord */
            $owner = $this->getOwner();
            $ownerClassName = get_class($owner);
            $ownerPk = $owner->getPrimaryKey();

            if (!$owner->isNewRecord) {
                $model = call_user_func(array($this->translationClass, 'model'));
                $criteria = new CdbCriteria();
                $criteria->params = array('id' => $ownerPk);
                $criteria->condition = "owner_id=:id";
                $criteria->index = 'lang_id';
                $translations = $model->findAll($criteria);
            }

            foreach ($this->languages as $lang) {
                if (!isset($translations[$lang])) {
                    $model = new $this->translationClass;
                    $model->owner_id = $ownerPk;
                    $model->lang_id = $lang;
                } else {
                    $model = $translations[$lang];
                }

                foreach ($this->attributes as $attribute) {
                    $model->{$attribute} = $_POST[$ownerClassName][$attribute.'_'.$lang];
                }
                /* @var $model CActiveRecord */
                $model->save(false);
            }
        }

        // Получить значение динамического свойства
        public function __get($name)
        {
            if (isset($this->dynamicAttributes[$name])) {
                return $this->dynamicAttributes[$name];
            }
            return null;
        }

        // Установить динамическое свойство
        public function __set($name, $value)
        {
            $this->dynamicAttributes[$name] = $value;
        }

        // Проверить существования динамического свойства
        public function __isset($name)
        {
            if (!parent::__isset($name)) {
                return (isset($this->dynamicAttributes[$name]));
            } else {
                return false;
            }
        }

        public function canGetProperty($name)
        {
            return true;
        }

        public function canSetProperty($name)
        {
            return true;
        }

    }
