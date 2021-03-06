<?php


class CodeOperation extends ActiveRecord {

    public function getTable()
    {
       return 'operation_codes'
    }
   /**
     * Обработка документов по обратному акцептированию.
     */
    public static function import416($unusedVar)
    {
        $docs = static::find()->andWhere(['operation_uid' => static::OPERATION_416, 'state' => static::STATE_CREATING])
            ->andWhere(new Expression('created_at> current_date - 14')) //ограничить проверку доков сроком - неделя/две
            ->all();
        
        foreach ($docs as $doc) {
            $codes = pghelper::pgarr2arr($doc->codes);
            $tree = [];
            
            foreach ($codes as $code) {
                if (!isset($tree[$code])) {
                    $tree[$code] = [];
                }
                //создаем 3 записи для sgtin sscc_up sscc_down
                $uc = UsoCache::find()->andWhere(
                    [
                        'codetype_uid'  => CodeType::CODE_TYPE_INDIVIDUAL,
                        'operation_uid' => $doc->id,
                        'code'          => $code,
                    ]
                )->one();
                
                if (empty($uc)) {
                    $uc = new UsoCache();
                    $uc->load(
                        [
                            'code'          => $code,
                            'codetype_uid'  => CodeType::CODE_TYPE_INDIVIDUAL,
                            'operation_uid' => $doc->id,
                            'object_uid'    => $doc->object_uid,
                        ],
                        ''
                    );
                    
                    $uc->save();
                } else {
                    if (UsoCache::STATE_RECEIVED == $uc->state) {
                        //ответ sgtin
                        $tree[$code]['sgtin'] = unserialize($uc->answer);
                    }
                }
                
                $uc = UsoCache::find()->andWhere(
                    [
                        'codetype_uid'  => CodeType::CODE_TYPE_GROUP,
                        'operation_uid' => $doc->id,
                        'code'          => $code,
                    ]
                )->one();
                
                if (empty($uc)) {
                    $uc = new UsoCache();
                    $uc->load(
                        [
                            'code'          => $code,
                            'codetype_uid'  => CodeType::CODE_TYPE_GROUP,
                            'operation_uid' => $doc->id,
                            'object_uid'    => $doc->object_uid,
                        ],
                        ''
                    );
                    $uc->save();
                } else {
                    if (UsoCache::STATE_RECEIVED == $uc->state) {
                        //ответ sscc_down
                        $tree[$code]['down'] = unserialize($uc->answer);
                    }
                }
                
                $uc = UsoCache::find()->andWhere(
                    [
                        'codetype_uid'  => 0,
                        'operation_uid' => $doc->id,
                        'code'          => $code,
                    ]
                )->one();
                
                if (empty($uc)) {
                    $uc = new UsoCache();
                    $uc->load(
                        [
                            'code'          => $code,
                            'codetype_uid'  => 0,
                            'operation_uid' => $doc->id,
                            'object_uid'    => $doc->object_uid,
                        ],
                        ''
                    );
                    
                    $uc->save();
                } else {
                    if (UsoCache::STATE_RECEIVED == $uc->state) {
                        //ответ sscc_up
                        $tree[$code]['up'] = unserialize($uc->answer);
                    }
                }
            }
            //проверка все ли ответы получены и надо ли добить 416 до отправки
            //не парсить пока нет файла!!!
            $errors = [];
            
            foreach ($tree as $code => $data) {
                if (isset($data['sgtin'])) {
                    if (isset($data['up']) || isset($data['down'])) {
                        $errors[] = $code . ': Некорректный ответ от Маркировки (Sgtin + SSCC)';
                    }
                    if (isset($data['sgtin']['sscc']) && !empty($data['sgtin']['sscc'])) {
                        $errors[] = $code . ': Не верхнего уровня, родитель ' . $data['sgtin']['sscc'];
                    }
                } else {
                    if (isset($data['up']) && isset($data['down'])) {
                        if (isset($data['up']['sscc']) && !empty($data['up']['sscc'])) {
                            $errors[] = $code . ': Не верхнего уровня, родитель ' . $data['up']['sscc'];
                        }
                    } else {
                        $errors[] = $code . ': Не полностью полученны данные по коду';
                    }
                }
            }
            
            if (empty($errors)) {
                echo 'Данные получены' . PHP_EOL;
                $doc->fns_state = 'Данные получены';
                $doc->note = 'Данные получены';
                
                $doc->invoice->updateVendor();
                
                if (empty($doc->invoice->vatvalue)) {
                    $trans = Yii::$app->db->beginTransaction();
                    
                    try {
                        $params = unserialize($doc->fns_params);
                        $params = [
                            'subject_id'     => $doc->object->fns_subject_id,
                            'shipper_id'     => $doc->invoice->dest_fns,
                            'operation_date' => $doc->cdt,
                            'doc_num'        => $doc->invoice->invoice_number,
                            'doc_date'       => $doc->invoice->invoice_date,
                            'receive_type'   => $doc->invoice->turnover_type ?? 1,
                            'contract_type'  => $doc->invoice->contract_type ?? 1,
                            'source'         => 1,
                        ];
                        
                        $xml = $doc->xml($params);
                        file_put_contents($doc->getFileName(), $xml);
                        
                        $cnt = $doc->import();
                        //перегенерим файл, так как в предыдущем нет кодов в БД
                        $xml = $doc->xml($params);
                        file_put_contents($doc->getFileName(), $xml);
                        $doc->state = Fns::STATE_CREATED;
                    } catch (Exception $ex) {
                        echo 'Ошибка импорта документа: ' . $doc->id . PHP_EOL;
                        echo $ex->getFile() . PHP_EOL;
                        echo $ex->getLine() . PHP_EOL;
                        echo $ex->getMessage() . PHP_EOL;
                        echo $ex->getTraceAsString() . PHP_EOL;
                        
                        $trans->rollBack();
                        
                        $doc->refresh();
                        $doc->fns_state = 'Ошибка формирования документа (не все поля заполнены)';
                        $doc->note = 'Ошибка формирования документа (не все поля заполнены)';
                        $doc->save(false, ['fns_state', 'note', 'indcnt']);
                        continue;
                    }
                    
                    $cnt = (int)$cnt;
                    
                    if ($cnt != $doc->invoice->codes_cnt) {
                        echo 'откатываемся - не совпадает количество' . PHP_EOL;
                        $trans->rollBack();
                        $doc->refresh();
                        $doc->fns_state = 'Количество кодов не соответствует накладной (Накладная: $cnt/Axapta: ' . $doc->invoice->codes_cnt . ')';
                        $doc->note = 'Количество кодов не соответствует накладной (Накладная: $cnt/Axapta: ' . $doc->invoice->codes_cnt . ')';
                        $doc->indcnt = $cnt;
                        $doc->save(false, ['fns_state', 'note', 'indcnt']);
                    } else {
                        $trans->commit();
                    }
                    
                    echo 'success' . PHP_EOL;
                    
                    $doc->indcnt = $cnt;
                    $doc->save(false, ['fns_state', 'note', 'indcnt', 'state']);
                } else {
                    echo 'накладная некорректно вернула данные' . PHP_EOL;
                    $doc->fns_state = $doc->invoice->vatvalue;
                    $doc->note = $doc->invoice->vatvalue;
                    $doc->save(false, ['fns_state', 'note', 'indcnt']);
                }
            } else {
                $doc->fns_state = implode("\n", $errors);
                $doc->note = implode("\n", $errors);
                $doc->save(false, ['fns_state', 'note', 'indcnt']);
            }
        }
    }   
}
