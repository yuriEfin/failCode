DROP FUNCTION IF EXISTS c1.make_removed_tsd_stage1_fns(json);

CREATE OR REPLACE FUNCTION c1.make_removed_tsd_stage1_fns(_in json)
  RETURNS boolean AS
$BODY$declare
	_row record;
	_userid bigint;
	_type varchar;
	_codes_codes varchar[];
	_productid bigint;
	_object_uid bigint;
	_record_code record;
	_rec_grpcode record;
	_rec record;
	_docid varchar;
	_note varchar;
	_cnt bigint[];
begin
	begin
		SELECT INTO _docid current_setting('itrack.docid')::varchar;
	EXCEPTION WHEN OTHERS THEN
		_docid = null;
	end;

	SELECT INTO _row * FROM json_to_record(_in) as (f1 bigint,f2 varchar,f3 bigint,f4 bigint,f5 varchar,f6 varchar);

	_userid = _row.f1;
	_codes_codes = translate(_row.f2,'[]','{}')::varchar[];
	_productid = _row.f3;
	_object_uid = _row.f4;
	_type = _row.f5;
	_note = _row.f6;


	FOR _rec IN SELECT codetype_uid,array_agg(code) as c FROM _get_codes_array(_codes_codes) as codes
								      LEFT JOIN generations ON codes.generation_uid = generations.id
		    GROUP by codetype_uid
	LOOP
		IF _rec.codetype_uid = 1 THEN
			_cnt[1] = cardinality(_rec.c);
			_cnt[2] = 0;
		ELSE
			_cnt[1] = 0;
			_cnt[2] = cardinality(_rec.c);
		END IF;
		IF _type = 'ext8' or _type = 'ext9' THEN
			INSERT INTO cache.operations_cache 
                                (indcnt,grpcnt,docid,product_uid,operation_uid,codes,object_uid,created_by,fnsid,data,created_at,created_time,note) 
                         VALUES (_cnt[1],_cnt[2],_docid,_productid,8,_rec.c,_object_uid,_userid,'552',array[_type,'',''],timeofday()::timestamp with time zone,timeofday()::timestamp with time zone,_note);
		ELSE
			IF _type != 'other' THEN
				INSERT INTO cache.operations_cache 
                                        (indcnt,grpcnt,docid,product_uid,operation_uid,codes,object_uid,created_by,fnsid,created_at,created_time,note) 
                                 VALUES (_cnt[1],_cnt[2],_docid,_productid,28,_rec.c,_object_uid,_userid,'541',timeofday()::timestamp with time zone,timeofday()::timestamp with time zone,_note);
			END IF;
		END IF;
	END LOOP;

	return true;

end;$BODY$
  LANGUAGE plpgsql VOLATILE SECURITY DEFINER
  COST 100;
ALTER FUNCTION c1.make_removed_tsd_stage1_fns(json)
  OWNER TO itrack;
