<?php 
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir."/accesslib.php");
require_once($CFG->dirroot."/course/renderer.php");



class OITUtils{
	static private $grades;
	
	static public function formatFilesize($path){
		$size = filesize($path);
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
		$power = $size > 0 ? floor(log($size, 1024)) : 0;
		return number_format($size / pow(1024, $power), 2, '.', ',') . ' ' . $units[$power];
	}

	function completed($courseid){
		global $DB, $USER;
		
		$my_user = $USER->id;
		$sql = "SELECT COUNT(c.id) FROM mdl_scorm_scoes_track AS st JOIN mdl_user AS u ON st.userid=u.id JOIN mdl_scorm AS sc ON sc.id=st.scormid	JOIN mdl_course AS c ON c.id=sc.course WHERE c.id=? AND st.value= ? AND u.id=?";
		
		
		$sco= $DB->count_records_sql($sql, array('c.id'=>$courseid,'st.value'=>'passed','u.id'=>$my_user));
			
		//$sco = $DB->get_records_sql ($sql);
				
		return $sco;
	}
	
	static public function getreporteusuarios(){
		global $DB;

		$sql="SELECT gg.id, CONCAT(u.firstname,' ',u.lastname) as nombre, 
		u.email as correo, u.suspended as estado, 
		u.lastaccess as fecha_actividad,
		SUM(CASE WHEN gg.rawgrade>=6 THEN 1 ELSE 0 END) as lecciones,
		SUM(CASE WHEN gg.rawgrade>=6 THEN 1 ELSE 0 END) as aprobadas,
		ROUND(
		SUM(
		CASE WHEN (
		SELECT SUM(CASE WHEN _gg.rawgrade>=6 THEN 1 ELSE 0 END) 
		FROM {grade_grades} _gg 
		JOIN {grade_items} _gi on _gi.id = _gg.itemid
		WHERE _gi.courseid=gi.courseid AND _gi.itemmodule='hvp' AND _gg.userid=gg.userid
		)=(
		SELECT COUNT(*) 
		FROM {grade_items} _gi
		WHERE _gi.courseid=gi.courseid AND _gi.itemmodule='hvp'
		) THEN 1/(
		SELECT COUNT(*) 
		FROM {grade_items} _gi
		WHERE _gi.courseid=gi.courseid AND _gi.itemmodule='hvp'
		) ELSE 0 END
		)
		) as cursos 
		FROM {user} u
		JOIN {grade_grades} gg ON gg.userid = u.id 
		JOIN {grade_items} gi on gi.id = gg.itemid 
		JOIN {course} c ON c.id = gi.courseid 
		WHERE u.deleted=0 AND u.username<>'guest' AND gi.itemmodule='hvp' 
		GROUP BY nombre ORDER BY fecha_actividad DESC";

		$resultado=$DB->get_records_sql($sql);
		$usuarios=array();

		foreach ( $resultado as $key => $value) {
			$item=$value;
			$item->estado=$value->estado?'suspendido':'activo';
			$item->fecha_actividad=format_time(time() - $value->fecha_actividad);
			$usuarios[]=$item;
		}

		return $usuarios;
	}

	/**
	 * Convertir un listado de tokens en html segun una plantilla
	 * 
	 * @param  String 				$plantilla 	html con tokens dinamicos especificados {{asi}}
	 * @param  array asociativo 	$array     	array asociativo con key llamados como los tokens dinamicos
	 *                           				de la plantilla
	 *                           				
	 * @return String            	html final generado a partir de una plantilla y un objeto de tokens
	 */
	static public function plantillarender($plantilla,$array){
		foreach ($array as $key => $value) {
			$plantilla=str_replace("{{{$key}}}", $value, $plantilla);
		}
		return $plantilla;
	}
	static public function geticon($icon){
		return "<i class='icon fa $icon fa-fw'></i>"; 
	}
	static private function setprogreso(int $estado){
		$barra='';
		$attr=array("data-toggle"=>"tooltip", "data-placement"=>"top", "title"=>"Hooray!");
		$estadostr=array("Diagnóstico","Lecciones","Evaluación");
		for ($i=0; $i <$estado ; $i++) { 
			$attr['class']="activado";
			$attr['title']=$estadostr[$i];
			$barra.=html_writer::tag('div',"",$attr);
		}
		for ($i=$estado; $i <3 ; $i++) { 
			$attr['class']="desactivado";
			$attr['title']=$estadostr[$i];
			$barra.=html_writer::tag('div',"",$attr);
		}
		return $barra;
	}
	static private function getaccioncurso($course){
		$aprobadas=self::$grades[$course->id]['aprobadas'];
		$reprobadas=self::$grades[$course->id]['reprobadas'];
		$totales=self::$grades[$course->id]['total_actividades'];
		//echo $totales."Totales<br>";
		//echo $aprobadas."Aprobadas<br>";
		//echo $reprobadas."Reprobadas<br>";
		
		$estado=self::setprogreso(0);
		$estado_boton='';
		$quiz=self::getmodpage($course,'quiz',true);
		// var_dump(self::$grades[$course->id]);
		$info=html_writer::tag('p',"¿Está listo para empezar?<br> mida su nivel de conocimientos.");
		$url=$quiz['Diagnóstico'];

		// var_dump(($aprobadas+$reprobadas)===$totales,($aprobadas+$reprobadas)!=0);

		$texto_boton='HACER DIAGNÓSTICO';
		if(!$course->visible){
			$info=html_writer::tag('p',"Estamos trabajando en los contenidos de este curso.");
			$estado_boton='gris';
			$texto_boton='PRÓXIMAMENTE';
		}elseif($course->bloqueado){
			$info=html_writer::tag('p',"¿Está listo para empezar?<br> mida su nivel de conocimientos.");
			$estado_boton='gris';
			$url=$quiz['Diagnóstico'];
			$texto_boton='HACER DIAGNÓSTICO <i class="fa fa-lock"></i>';
		}elseif(self::$grades[$course->id]['diagnostico']&&self::$grades[$course->id]['diagnostico']&&($aprobadas+$reprobadas)!==$totales){
			$info=html_writer::tag('h1',($aprobadas+$reprobadas)."/$totales");
			$info.=html_writer::tag('p',"Lecciones");
			$url="/course/view.php?id=$course->id";
			$estado_boton='';
			$texto_boton='CONTINUAR CURSO';
			$estado=self::setprogreso(1);

//		}elseif(self::$grades[$course->id]['diagnostico']&&($aprobadas+$reprobadas)===$totales&&($aprobadas+$reprobadas)!=0&&!self::$grades[$course->id]['evaluacion']&&$course->id!=="17"){
		}elseif(self::$grades[$course->id]['diagnostico']&&($aprobadas+$reprobadas)===$totales&&($aprobadas+$reprobadas)!=0&&!self::$grades[$course->id]['evaluacion']){

			$info=html_writer::tag('p',"¡Felicitaciones!<br> ha terminado todas las lecciones.");
			$url=$quiz['Evaluación'];
			$estado_boton='';
			$texto_boton='HACER EVALUACIÓN';
			$estado=self::setprogreso(2);

		}elseif(self::$grades[$course->id]['evaluacion']){
//		}elseif(self::$grades[$course->id]['evaluacion']||$course->id==="17"){

		//por qué course_id 17?
		
			$puntajeLecciones=round(50*self::$grades[$course->id]['puntaje']/self::$grades[$course->id]['puntaje_max']);
			$puntajeEval=5*self::$grades[$course->id]['puntaje_evaluacion'];
			$nota=round($puntajeLecciones+$puntajeEval);
			$nota=$course->id==="17"?round(10*self::$grades[$course->id]['puntaje']/($totales),2):$nota;

			// var_dump($nota);
			$nota=is_nan($nota)?"100":$nota;
			$url="/course/view.php?id=$course->id";

			$info=html_writer::tag('h1',$nota);
			$info.=html_writer::tag('p',"Puntos");
			$notaEvaluacion=$course->id==="17"?"":"Evaluación: ".(5*self::$grades[$course->id]['puntaje_evaluacion']);
			// $info.=html_writer::tag('h4',"Leccion: ".(($course->id==="17"?10:5)*self::$grades[$course->id]['puntaje']/($totales))." $notaEvaluacion");
			$estado_boton='';
			$texto_boton='REPASAR CURSO';
			$estado=self::setprogreso(3);
		}


		$link=html_writer::link($url, $texto_boton,array('class'=>"oit-boton $estado_boton"));
		return array("info"=>$info,
			"estado"=>$estado,
			"link"=>$link);
	}
	static public function issupervisor($userid){
		global $DB;
		return $DB->count_records('oit_supervisor_usuarios',array('id_supervisor'=>$userid))!=0;
	}
	

	
	static private function updategrades($userid){
		global $DB,$USER,$CFG;
		$sql='SELECT gg.itemid, gg.finalgrade, gg.rawgrademax, i.courseid FROM '.$CFG->prefix.'grade_grades gg JOIN '.$CFG->prefix.'grade_items i ON i.id=gg.itemid ';
		$sql.="WHERE gg.userid=$userid AND gg.aggregationstatus='used'";
		$grades=$DB->get_records_sql($sql);
		//echo $USER->id;
		
		
		$res=array();
		foreach ($grades as $key => $value) {
			$item=$DB->get_record('grade_items',array('id' => $value->itemid),'*');
			$actividades=$DB->count_records('grade_items',array('courseid'=>$value->courseid,'itemtype'=>'mod','itemmodule'=>'hvp'));
			$puntaje=$item->itemmodule==='hvp'?(int)$value->finalgrade:$value->finalgrade;
			
			$actividadSco=(count($DB->get_records_sql("select * from mdl_grade_items where courseid='$value->courseid' and itemmodule='scorm'")));
			
			$scoCompleted=self::completed($value->courseid);	
			
			
			
			//JAMP 171019 - El puntaje requiere una calificación del scorm
			
			$puntajeSco=$item->itemmodule==='scorm'?(int)$value->finalgrade:$value->finalgrade;
			
			//
			
			
			$puntajemax=(int)$item->grademax;
			//echo $puntajemax;

			
			//
			
						
			
			$res[$value->courseid][$item->itemmodule.$item->iteminstance]['puntaje']=$puntaje;
			$res[$value->courseid][$item->itemmodule.$item->iteminstance]['puntaje_max']=$puntajemax;
			$res[$value->courseid][$item->itemmodule.$item->iteminstance]['aprobo']=($puntaje/$puntajemax)>=0.6;

			$res[$value->courseid]['diagnostico']=$res[$value->courseid]['diagnostico']?$res[$value->courseid]['diagnostico']:$item->itemname==='Diagnóstico';
			$res[$value->courseid]['evaluacion']=$res[$value->courseid]['evaluacion']?$res[$value->courseid]['evaluacion']:$item->itemname==='Evaluación';
			
			$res[$value->courseid]['total_actividades']=$actividades;
			
			//JAMP 171019
			
			if ($actividadSco!=null) {
				
				$res[$value->courseid][$item->itemmodule.$item->iteminstance]['puntaje']=$puntajeSco;
				$res[$value->courseid][$item->itemmodule.$item->iteminstance]['puntaje_max']=$puntajemax;
				$res[$value->courseid][$item->itemmodule.$item->iteminstance]['aprobo']=($puntajeSco/$puntajemax)>=0.6;

				$res[$value->courseid]['diagnostico']=$res[$value->courseid]['diagnostico']?$res[$value->courseid]['diagnostico']:$item->itemname==='Diagnóstico';
				$res[$value->courseid]['evaluacion']=$res[$value->courseid]['evaluacion']?$res[$value->courseid]['evaluacion']:$item->itemname==='Evaluación';
				$res[$value->courseid]['total_actividades']=$actividadSco;
			
			}
			
			//
			
			//JAMP 171019
		
			if($item->itemmodule='hvp'){
				$res[$value->courseid]['puntaje']+=$puntaje;
				$res[$value->courseid]['puntaje_max']+=$puntajemax;
				$res[$value->courseid]['aprobadas']+=($puntaje/$puntajemax)>=0.6;
				$res[$value->courseid]['reprobadas']+=($puntaje/$puntajemax)<0.6;
			}elseif($item->itemname==='Evaluación'){
				$res[$value->courseid]['puntaje_evaluacion']+=$puntaje;
			}elseif($item->itemname==='Diagnóstico'){
				$res[$value->courseid]['puntaje_diagnostico']+=$puntaje;
			}
			
						if($item->itemmodule='scorm'){
				$res[$value->courseid]['aprobadas']=0;			
				$res[$value->courseid]['reprobadas']=0;
							
			
				$res[$value->courseid]['puntaje']+=$puntajeSco;
				$res[$value->courseid]['puntaje_max']+=$puntajemax;
				$res[$value->courseid]['aprobadas']=self::completed($value->courseid);
				//$res[$value->courseid]['reprobadas']+=($puntajeSco/$puntajemax)<0.6;
				
				//echo self::completed($value->courseid);
				//echo $value->courseid."+".$scoCompleted."Completados<br>";
				//echo $actividadSco."Totales<br>";
				//echo $puntajeSco/$puntajemax."puntajeSco/puntajeMax<br>";




				
			}elseif($item->itemname==='Evaluación'){
				$res[$value->courseid]['puntaje_evaluacion']+=$puntajeSco;
			}elseif($item->itemname==='Diagnóstico'){
				$res[$value->courseid]['puntaje_diagnostico']+=$puntajeSco;
			}
			
			//
		}
		self::$grades=$res;
	}
	static public function getpuntajemax(int $courseid){
		global $DB;
		if(!is_int($courseid)) return false;
		$puntajesmaximoslecciones=$DB->get_records('grade_items',array('courseid'=>$courseid,'itemmodule'=>'hvp'));
		//JAMP 171019
		//$puntajesmaximosleccionesSco=$DB->get_records('grade_items',array('courseid'=>$courseid,'itemmodule'=>'scorm'));
		
		$puntajesmaximosleccionesSco=$DB->get_records_sql("select * from mdl_grade_items where courseid='$courseid' and itemmodule='scorm'");
		
		
		//
		$puntajemaxcurso=1;
		if(is_array($puntajesmaximoslecciones)){
			$puntajemaxcurso=0;
			foreach ($puntajesmaximoslecciones as $key => $value) {
				$puntajemaxcurso+=intval($value->grademax);
			}
		}
		
		//JAMP 171019
		if(is_array($puntajesmaximosleccionesSco)){
			$puntajemaxcurso=0;
			foreach ($puntajesmaximosleccionesSco as $key => $value) {
				$puntajemaxcurso+=intval($value->grademax);
			}
		}
		//
		return $puntajemaxcurso;
	}
	static public function normalize($string){
		$str=preg_replace('(&iquest;|\¿|\?|\:|\,|\.|\(|\))', '',htmlentities($string, ENT_QUOTES, 'UTF-8'));
		$str=str_replace(' ', '-',$str);
		$str=preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $str);
		return strtolower($str);
	}
	static public function getmodpage($course,String $modname,$diccionario=false)	{
		$modinfo = get_fast_modinfo($course);
		$modarray= array();
		foreach ($modinfo->get_section_info_all() as $section => $thissection) {
			$section=$modinfo->get_section_info($thissection->section);
			foreach ($modinfo->sections[$section->section] as $key => $value) {
				$mod=$modinfo->cms[$value];
				if($mod->modname===$modname){
					if($diccionario){
						// var_dump($mod->name);
						$modarray[$mod->name]=$mod->available?new moodle_url("/mod/$modname/view.php", array('id' => $mod->id)):null;
					}else {
						array_push($modarray, new moodle_url("/mod/$modname/view.php", array('id' => $mod->id)));
					}
				}
			}
		}

		if(count($modarray)===1&&!$diccionario){
			return $modarray[0];
		}

		if(count($modarray)>=1){
			return $modarray;
		}
	}	
	static public function getmenudesplegable(){
		global $OUTPUT, $DB, $SESSION, $CFG,$PAGE,$USER;
		$returnobject = new stdClass();
		$returnobject->navitems = array();
		$returnobject->metadata = array();

		$course = $PAGE->course;

    	// Query the environment.
		$context = context_course::instance($course->id);


    	// Get basic user metadata.
		$returnobject->metadata['userid'] = $USER->id;
		$returnobject->metadata['userfullname'] = fullname($USER, true);
		$returnobject->metadata['userprofileurl'] = new moodle_url('/user/perfil.php', array(
			'id' => $USER->id
		));

		$avataroptions = array('link' => false, 'visibletoscreenreaders' => false);
		if (!empty($options['avatarsize'])) {
			$avataroptions['size'] = $options['avatarsize'];
		}
		$returnobject->metadata['useravatar'] = $OUTPUT->user_picture (
			$USER, $avataroptions
		);
    	// Build a list of items for a regular user.

    	// Query MNet status.
		if ($returnobject->metadata['asmnetuser'] = is_mnet_remote_user($USER)) {
			$mnetidprovider = $DB->get_record('mnet_host', array('id' => $USER->mnethostid));
			$returnobject->metadata['mnetidprovidername'] = $mnetidprovider->name;
			$returnobject->metadata['mnetidproviderwwwroot'] = $mnetidprovider->wwwroot;
		}

    	// Did the user just log in?
		if (isset($SESSION->justloggedin)) {
        	// Don't unset this flag as login_info still needs it.
			if (!empty($CFG->displayloginfailures)) {
            	// Don't reset the count either, as login_info() still needs it too.
				if ($count = user_count_login_failures($USER, false)) {

                	// Get login failures string.
					$a = new stdClass();
					$a->attempts = html_writer::tag('span', $count, array('class' => 'value'));
					$returnobject->metadata['userloginfail'] =
					get_string('failedloginattempts', '', $a);

				}
			}
		}

    	// Links: Course.
		$myhome = new stdClass();
		$myhome->itemtype = 'link';
		$myhome->url = new moodle_url('/');
		$myhome->title = get_string('courses');
		$myhome->titleidentifier = 'courses';
		$myhome->pix = "i/log";
		$returnobject->navitems[] = $myhome;

		// Links: Recurso.
		$myhome = new stdClass();
		$myhome->itemtype = 'link';
		$myhome->url = new moodle_url('/oit/recursos.php');
		$myhome->title = 'Recursos';
		$myhome->titleidentifier = 'recurso';
		$myhome->pix = "i/folder";
		$returnobject->navitems[] = $myhome;

		// Links: Course.
		$myhome = new stdClass();
		$myhome->itemtype = 'link';
		$myhome->url = new moodle_url('/oit/glosario.php');
		$myhome->title = 'Glosario';
		$myhome->titleidentifier = 'glosario';
		$myhome->pix = "t/viewdetails";
		$returnobject->navitems[] = $myhome;

    	// Links: Panel.
		// if(self::issupervisor($USER->id)){
		$myprofile = new stdClass();
		$myprofile->itemtype = 'link';
		$myprofile->url = new moodle_url('/oit/panel.php');
		$myprofile->title = 'Panel';
		$myprofile->titleidentifier = 'panel,moodle';
		$myprofile->pix = "i/stats";
		$returnobject->navitems[] = $myprofile;
		// }

    	// Links: My Profile.
		$myprofile = new stdClass();
		$myprofile->itemtype = 'link';
		$myprofile->url = new moodle_url('/oit/perfil.php', array('id' => $USER->id));
		$myprofile->title = get_string('profile');
		$myprofile->titleidentifier = 'profile,moodle';
		$myprofile->pix = "i/user";
		$returnobject->navitems[] = $myprofile;

    	// Links: Portadas.
		$myprofile = new stdClass();
		$myprofile->itemtype = 'link';
		$myprofile->url = new moodle_url('/oit/portadas.php', array('id' => $USER->id));
		$myprofile->title = 'Portadas';
		$myprofile->titleidentifier = 'portadas,moodle';
		$myprofile->pix = "i/settings";
		$returnobject->navitems[] = $myprofile;


		$returnobject->metadata['asotherrole'] = false;
		foreach ($customitems as $item) {
			$returnobject->navitems[] = $item;
		}


		if ($returnobject->metadata['asotheruser'] = \core\session\manager::is_loggedinas()) {
			$realuser = \core\session\manager::get_realuser();

        	// Save values for the real user, as $USER will be full of data for the
        	// user the user is disguised as.
			$returnobject->metadata['realuserid'] = $realuser->id;
			$returnobject->metadata['realuserfullname'] = fullname($realuser, true);
			$returnobject->metadata['realuserprofileurl'] = new moodle_url('/user/profile.php', array(
				'id' => $realuser->id
			));
			$returnobject->metadata['realuseravatar'] = $OUTPUT->user_picture($realuser, $avataroptions);

        	// Build a user-revert link.
			$USERrevert = new stdClass();
			$USERrevert->itemtype = 'link';
			$USERrevert->url = new moodle_url('/course/loginas.php', array(
				'id' => $course->id,
				'sesskey' => sesskey()
			));
			$USERrevert->pix = "a/logout";
			$USERrevert->title = get_string('logout');
			$USERrevert->titleidentifier = 'logout,moodle';
			$returnobject->navitems[] = $USERrevert;

		} else {

        	// Build a logout link.
			$logout = new stdClass();
			$logout->itemtype = 'link';
			$logout->url = new moodle_url('/login/logout.php', array('sesskey' => sesskey()));
			$logout->pix = "a/logout";
			$logout->title = get_string('logout');
			$logout->titleidentifier = 'logout,moodle';
			$returnobject->navitems[] = $logout;
		}

		if (is_role_switched($course->id)) {
			if ($role = $DB->get_record('role', array('id' => $USER->access['rsw'][$context->path]))) {
            	// Build role-return link instead of logout link.
				$rolereturn = new stdClass();
				$rolereturn->itemtype = 'link';
				$rolereturn->url = new moodle_url('/course/switchrole.php', array(
					'id' => $course->id,
					'sesskey' => sesskey(),
					'switchrole' => 0,
					'returnurl' => $PAGE->url->out_as_local_url(false)
				));
				$rolereturn->pix = "a/logout";
				$rolereturn->title = get_string('switchrolereturn');
				$rolereturn->titleidentifier = 'switchrolereturn,moodle';
				$returnobject->navitems[] = $rolereturn;

				$returnobject->metadata['asotherrole'] = true;
				$returnobject->metadata['rolename'] = role_get_name($role, $context);

			}
		} else {
        	// Build switch role link.
			$roles = get_switchable_roles($context);
			if (is_array($roles) && (count($roles) > 0)) {
				$switchrole = new stdClass();
				$switchrole->itemtype = 'link';
				$switchrole->url = new moodle_url('/course/switchrole.php', array(
					'id' => $course->id,
					'switchrole' => -1,
					'returnurl' => $PAGE->url->out_as_local_url(false)
				));
				$switchrole->pix = "i/switchrole";
				$switchrole->title = get_string('switchroleto');
				$switchrole->titleidentifier = 'switchroleto,moodle';
				$returnobject->navitems[] = $switchrole;
			}
		}

		return $returnobject;
	}

	static private function getSeccion($id,$etapa,$cmid=null){
		global $USER,$CFG;

		$estadoPendiente=html_writer::img("/oit/img/menu/$etapa/ico_seccion_pendiente.png","icono");
		$estadoBloqueado=html_writer::img("/oit/img/menu/$etapa/ico_seccion_bloq.png","icono");
		$estadoTerminado=html_writer::img("/oit/img/menu/$etapa/ico_seccion_terminado.png","icono");

		$seccion=(object) array('puntaje'=>0,'estado'=>$estadoBloqueado);
		
		self::updategrades($USER->id);
		$totales=self::$grades[$id]['total_actividades'];
		$aprobadas=self::$grades[$id]['aprobadas'];
		$reprobadas=self::$grades[$id]['reprobadas'];
		$total_actividades=self::$grades[$id]['total_actividades'];
		
		if ($cmid===null) {
			$puntajeLecciones=round(50*self::$grades[$id]['puntaje']/self::$grades[$id]['puntaje_max']);
			$seccion->puntaje=!is_nan($puntajeLecciones)?$puntajeLecciones:0;
			if(!self::$grades[$id]['diagnostico']){
				$seccion->estado=$estadoBloqueado;
			}elseif(($aprobadas+$reprobadas)<$totales){
				$seccion->estado=$estadoPendiente;
			}else{
				$seccion->estado=$estadoTerminado;
			}
			return $seccion;
		}

		include_once $CFG->dirroot.'/mod/quiz/locallib.php';
		include_once $CFG->dirroot.'/mod/quiz/attemptlib.php';
		include_once $CFG->dirroot.'/mod/quiz/lib.php';

		$cm = get_coursemodule_from_id('quiz',$cmid);
		$quizobj = quiz::create($cm->instance, $USER->id);
		$quiz = $quizobj->get_quiz();
		$attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
		
		if($cm->name=='Diagnóstico'){
			$seccion->estado=$estadoPendiente;
			$seccion->puntaje='';
		}elseif($cm->name=='Evaluación'){
			if(($aprobadas+$reprobadas)==$total_actividades&&($aprobadas+$reprobadas)!=0){
				$seccion->estado=$estadoPendiente;
			}
		}

		if(count($attempts)){
			$attemptobj=end($attempts);

			$seccion->estado=$estadoTerminado;
			$seccion->puntaje=$cm->name=='Diagnóstico'?'':round(50*$attemptobj->sumgrades/$quiz->sumgrades);
		}
		return $seccion;
	}

	/**
	 * Funcion que modifica el comportamiento del menu lateral
	 * 
	 * @return flat_navigation 			Menu de navegacion lateral
	 */
	static public function getmenu(){
		global $PAGE,$USER,$CFG;

		if(method_exists($PAGE->context, get_course_context)){
			$courseid=$PAGE->context->get_course_context(false)->instanceid;
		}
		$flat=$PAGE->flatnav;
		foreach ($flat as &$value) {
			$item=$value;
			switch ($value->key) {
				case 'coursehome':

				$value->text="<h1>".$value->text."</h1>";
				$value->visible=true;
				$value->action=false;
				break;
				case 'profile':
				$value->visible=true;
				break;
				case 'participants':
				case 'home':
				case 'calendar':
				case 'competencies':
				case 'badgesview':
				case 'privatefiles':
				case 'mycourses':
				case 'myhome':
				$value->visible=false;
				break;
				break;
				//Se especifican las excepciones para los items de menu que tienen puntaje
				case 'Diagnóstico':
				
				//Se vuelven visibles
				$value->visible=true;

				//Se setea el parametro seccion
				$value->seccion=self::getSeccion($courseid,1,$value->action->get_param('id'));
				break;
				case 'course':
				$value->visible=true;
				$value->seccion=self::getSeccion($courseid,2);
				break;
				case 'Evaluación':
				$value->visible=true;
				$value->seccion=self::getSeccion($courseid,3,$value->action->get_param('id'));
				default:
				if($value->parent->key=='mycourses'||is_numeric($value->key)){
					$value->visible=false;
				}else{
					$value->visible=true;
				}
				break;
			}
		}

		if($courseid){
			//Se crea un item del menu de navegacion 
			$menuItemArray=array('action'=>false,
				'text'=>'Puntos',
				'key'=>'puntaje',
				'action'=>false);

			$menuItem=new navigation_node($menuItemArray,0);

			//Se setean parametros adicionales  
			$menuItem->visible=true;
			$menuItem->action= new moodle_url("/oit/ranking.php?id=$courseid");
			$menuItem->indent=0;

			$menuItem->color='gris';

			if(isset(self::$grades[$courseid]['puntaje'])){
				$menuItem->color=self::$grades[$courseid]['puntaje_max']/2>self::$grades[$courseid]['puntaje']?'rojo':'verde	';
			}

			$puntajeLecciones=round(50*self::$grades[$courseid]['puntaje']/self::$grades[$courseid]['puntaje_max']);
			$puntajeLecciones=!is_nan($puntajeLecciones)?$puntajeLecciones:0;

			//Se setea el valor del puntaje adicional
			$menuItem->puntaje=round($puntajeLecciones+5*self::$grades[$courseid]['puntaje_evaluacion']);
			$menuItem->verPuntaje=true;

			//Se adiciona el item del puntaje al menu de navegacion
			$flat->add($menuItem,'folder');
		}

		return $flat;
	}

	static public function enroll_to_course($courseid, $userid, $roleid=5, $extendbase=3, $extendperiod=0)  {
		global $DB;

		$instance = $DB->get_record('enrol', array('courseid'=>$courseid, 'enrol'=>'manual'), '*', MUST_EXIST);
		$course = $DB->get_record('course', array('id'=>$instance->courseid), '*', MUST_EXIST);
		$today = time();
		$today = make_timestamp(date('Y', $today), date('m', $today), date('d', $today), 0, 0, 0);

		if(!$enrol_manual = enrol_get_plugin('manual')) { throw new coding_exception('Can not instantiate enrol_manual'); }
		switch($extendbase) {
			case 2:
			$timestart = $course->startdate;
			break;
			case 3:
			default:
			$timestart = $today;
			break;
		}  
		if ($extendperiod <= 0) { $timeend = 0; }  
		else { $timeend = $timestart + $extendperiod; }
		$enrolled = $enrol_manual->enrol_user($instance, $userid, $roleid, $timestart, $timeend);
		add_to_log($course->id, 'course', 'enrol', '../enrol/users.php?id='.$course->id, $course->id);

		return $enrolled;
	}
	static public function cursosrenderer(){
		global $USER,$DB;
		$userid = $USER->id;
		$out='';
		//JAMP 171019 - Cursos de la portada
		$cursosIntro = array(18,14,12,6,7,8,9,10,15,3,16);

		foreach ($cursosIntro as $curso) {
			$context = context_course::instance($curso);
			if(!is_enrolled($context, $userid, '', true)){
				self::enroll_to_course($curso, $userid);
			}
		}


		$cursos=$DB->get_records('course',null,'sortorder');
		self::updategrades($userid);


		foreach ($cursos as $value) {
			$value->bloqueado=false;

			$context = context_course::instance($value->id);
			if ($value->format=='topics'&&(is_siteadmin()||is_enrolled($context, $userid, '', true))){
				$out.=self::cajacurso($value);
			}
		}
		$js="setBootstrapTooltips();";
		$out.=html_writer::tag('script',$js);
		return $out;
	}
	static public function cajacurso($curso, $additionalclasses = ''){
		global $DB,$CFG;
		$plantilla=file_get_contents($CFG->dirroot.'/oit/plantillas/cajacursos.html');
		$shortname=self::normalize($curso->shortname);
		// var_dump(file_exists($CFG->dirroot."/oit/cursos/$shortname/curso.jpg")?"":$shortname);
		$caja=str_replace("{{titulo}}",$curso->fullname , $plantilla);
		// var_dump($shortname);
		$background=file_exists($CFG->dirroot."/oit/portadas/$shortname.jpg")?"url($CFG->wwwroot/oit/portadas/$shortname.jpg) right":"#696969";
		$caja=str_replace("{{background}}",$background , $caja);
		foreach (self::getaccioncurso($curso) as $key => $value) {
			$caja=str_replace("{{".$key."}}",$value , $caja);

		}


		return $caja;
	}
	static public function getgrades($userid){
		self::updategrades($userid);
		// var_dump(self::$grades);
		return self::$grades;
	}
	static public function handleresult(){
		global $CFG;
		require_once($CFG->dirroot."/mod/hvp/locallib.php");
		//QUITAR ESTO
		$skipToken=optional_param('skipToken',0, PARAM_INT);
		if($skipToken!=1){

        // Validate token.
			if (!\mod_hvp\xapi_result::validate_token()) {
				$core = framework::instance();
				\H5PCore::ajaxError($core->h5pF->t('Invalid security token.'),
					'INVALID_TOKEN');
				return;
			}
			\H5PCore::ajaxError('Invalid request');
			return;
		}else{

			$cm = get_coursemodule_from_id('hvp', required_param('contextId', PARAM_INT));
			if (!$cm) {
				\H5PCore::ajaxError('No such content');
				http_response_code(404);
				return;
			}

			$xapiresult = required_param('xAPIResult', PARAM_RAW);

        // Validate.
			$context = \context_module::instance($cm->id);
			if (!has_capability('mod/hvp:saveresults', $context)) {
				\H5PCore::ajaxError(get_string('nopermissiontosaveresult', 'hvp'));
				return;
			}

			$xapijson = json_decode($xapiresult);
			if (!$xapijson) {
				\H5PCore::ajaxError('Invalid json in xAPI data.');
				return;
			}

			if (!\mod_hvp\xapi_result::validate_xapi_data($xapijson)) {
				\H5PCore::ajaxError('Invalid xAPI data.');
				return;
			}

        // Delete any old results.
			\mod_hvp\xapi_result::remove_xapi_data($cm->instance);

        // Store results.
			\mod_hvp\xapi_result::store_xapi_data($cm->instance, $xapijson);

        // Successfully inserted xAPI result.
			\H5PCore::ajaxSuccess();
		}
	}
	static public function handlefinished(){
		global $DB, $USER,$CFG;
		require_once($CFG->dirroot."/mod/hvp/locallib.php");
		require_once($CFG->dirroot."/mod/hvp/lib.php");

		if (!\H5PCore::validToken('result', required_param('token', PARAM_RAW))) {
			\H5PCore::ajaxError(get_string('invalidtoken', 'hvp'));
			return;
		}

		$cm = get_coursemodule_from_id('hvp', required_param('contextId', PARAM_INT));
		if (!$cm) {
			\H5PCore::ajaxError('No such content');
			http_response_code(404);
			return;
		}

        // Content parameters.
		$score = required_param('score', PARAM_INT);
		$maxscore = required_param('maxScore', PARAM_INT);

        // Check permission.
		$context = \context_module::instance($cm->id);
		if (!has_capability('mod/hvp:saveresults', $context)) {
			\H5PCore::ajaxError(get_string('nopermissiontosaveresult', 'hvp'));
			http_response_code(403);
			return;
		}

        // Get hvp data from content.
		$hvp = $DB->get_record('hvp', array('id' => $cm->instance));
		if (!$hvp) {
			\H5PCore::ajaxError('No such content');
			http_response_code(404);
			return;
		}

        // Create grade object and set grades.
		$grade = (object) array(
			'userid' => $USER->id
		);

        // Set grade using Gradebook API.
		$hvp->cmidnumber = $cm->idnumber;
		$hvp->name = $cm->name;
		$hvp->rawgrade = $score;
		$hvp->rawgrademax = $maxscore;
		hvp_grade_item_update($hvp, $grade);

        // Get content info for log.
		$content = $DB->get_record_sql(
			"SELECT c.name AS title, l.machine_name AS name, l.major_version, l.minor_version
			FROM {hvp} c
			JOIN {hvp_libraries} l ON l.id = c.main_library_id
			WHERE c.id = ?",
			array($hvp->id)
		);

        // Log results set event.
		new \mod_hvp\event(
			'results', 'set',
			$hvp->id, $content->title,
			$content->name, $content->major_version . '.' . $content->minor_version
		);

		\H5PCore::ajaxSuccess();
	}

	/**
	 *	Devuelve un arreglo de objetos con los campos id y nombre completo que esten dentro 
	 * 	del departamento especificado 
	 * 
	 * @param  String o null 		$departamento 		Nombre del departamento a filtrar
	 * 
	 * @return array<stdObject> 	un arreglo de objetos con los campos id y nombre completo que esten dentro 
	 * 								del departamento especificado 
	 */
	static public function getusers($departamento=null,$id=null){
		global $DB;
		

		//Query para obtener los usuarios activos
		$SQL = "SELECT 	u.id,
		u.firstname,
		u.lastname,
		u.picture,
		u.email,
		u.lastaccess,
		u.imagealt,
		uid.data AS departamento
		FROM {user} u
		INNER JOIN {user_info_data} uid ON u.id = uid.userid 
		WHERE (u.deleted = 0)";

		//Si existe departamento adicionar condiciones para filtrar por departamento
		//uid.fieldid = 1 es el id del campo personalizado de departamento
		$SQL.=$departamento!==null?"AND (uid.fieldid = 2) AND (uid.data = '$departamento')":"";
		$SQL.=$id!==null?"AND (u.id = $id)":"";

		try {
			return $DB->get_records_sql($SQL);
		} catch (Exception $e) {
			var_dump($SQL,$e->getMessage());
			die;
		}
	}

	/**
	 * Devuelve una lista de los ids de usuarios del departamento especificado categorizados 
	 * por pedientes de iniciar el curso, realizando y terminado el curso, estos 
	 * dos ultimos con las subcategorias bien y mal
	 * 
	 * @param  int 					$courseid 			Id del curso del que se quiere el reporte
	 * @param  String 				$departamento 		Nombre del departamento por el cual filtrar
	 * 
	 * @return array asociativo		Listado de ids de usuarios del departamento especificado categorizados 
	 * 								por pedientes de iniciar el curso, realizando y terminado el curso, estos 
	 * 								dos ultimos con las subcategorias bien y mal
	 *								se devuelve el siguiente array
	 *								array(
	 *								'terminaron'=>array(
	 *													'bien'=>array(),
	 *													'mal'=>array()
	 *													),
	 *								'encurso'=>array(),
	 *								'pendientes'=>array()
	 *								)
	 * 
	 */			
	static public function getreporte($courseid,$departamento=null){
		global $DB;

		$encurso=$pendientes=array();
		$terminaron=array('bien'=>array(),'mal'=>array());

		//Obtener los usuarios del reporte
		$usuarios=self::getusers($departamento);
		


		foreach ($usuarios as $user) {
			$nota=self::getgrades($user->id)[$courseid];

			//Revisar que no hallan comenzado ninguna actividad del curso
			if(!$nota['diagnostico']){

				$pendientes[]=$user;

			//Revisar que hallan terminado todas las actividades del curso
			}elseif (($nota['total_actividades']==($nota['aprobadas']+$nota['reprobadas']))&&$nota['evaluacion']) {

				//Calcular el puntaje total
				$puntajetotal=5*$nota['puntaje_evaluacion']+50*$nota['puntaje']/$nota['puntaje_max'];

				//Si el puntaje es mayor que 60 meter al usuario en la subcategoria bien, 
				//de lo contrario en la subcategoria mal
				$terminaron[$puntajetotal>60?'bien':'mal'][]=$user;

			//Si no cumple ninguna de las dos condiciones anteriores el usuario esta realizando el curso
			}else {
				$encurso[]=$user;
			}
		}

		return array('terminaron'=>$terminaron,'encurso'=>$encurso,'pendientes'=>$pendientes);
	}

	/**
	 * Devuelve un listado con los usuarios que han terminado o estan realizando el curso especificado
	 * filtrado por departamento y estado
	 * 
	 * @param  int 		$courseid     	Id del curso
	 * @param  array 	$reporte      	Reporte del curso
	 * @param  string 	$estado       	filtro de estado En curso, Terminado bien o Terminado mal
	 * @param  string 	$departamento 	filtro de departamento
	 * @return array               		array de arrays asociativos con los siguientes campos:
	 *                                     {{color}}
	 *                                     {{imagen}}
	 *                                     {{firstname}}
	 *                                     {{lastname}}
	 *                                     {{departamento}}
	 *                                     {{actividad}}
	 *                                     {{evaluacion}}
	 *                                     {{total}}
	 */
	static public function getRanking($courseid,$reporte,$estado,$departamento){
		global $PAGE;

		$usuarios=$departamento=='Todos'?self::getusers():self::getusers($departamento);

		$ranking=array();

		$renderer = $PAGE->get_renderer('core_user', 'myprofile');

		$usuariosRanking=array_merge($reporte['terminaron']['mal'],$reporte['terminaron']['bien'],$reporte['encurso']);

		foreach ($usuarios as $usuario) {
			$nota=self::getgrades($usuario->id)[$courseid];
			$actividad=round(50*$nota['puntaje']/$nota['puntaje_max']);
			$evaluacion=round(5*$nota['puntaje_evaluacion']);
			$total=$actividad+$evaluacion;

			$color=false;

			if(in_array($usuario, $reporte['terminaron']['mal'])&&!($estado=='Aprobaron'||$estado=='En curso')){

				$color='rojo';

			}elseif(in_array($usuario, $reporte['terminaron']['bien'])&&!($estado=='Reprobaron'||$estado=='En curso')){

				$color='verde';

			}elseif(in_array($usuario, $reporte['encurso'])&&!($estado=='Aprobaron'||$estado=='Reprobaron')){ 

				$color='amarillo';

			}

			if ($color) {
				$puntajes=array(
					'imagen'=>$renderer->user_picture($usuario,array('size'=>40)),
					'actividad'=>intval($actividad),
					'evaluacion'=>$evaluacion,
					'total'=>intval($total),
					'color'=>$color);

				$usuario=array_merge((array)$usuario,$puntajes);

				$ranking[]=$usuario;
			}
		}
		return $ranking;
	}
	static public function getInactivos($reporte){
		global $PAGE;
		$usuarios=array();

		//Objeto para renderizar imagen de perfil
		$renderer = $PAGE->get_renderer('core_user', 'myprofile');

		//Iterar sobre la lista de usuarios pendientes
		foreach ($reporte['pendientes'] as $id=>$valores) {
			//Armar array de tokens para la plantilla de tabla de usuarios
			$usuario=array(
				'imagen'=>$renderer->user_picture($valores,array('size'=>40)),
				'ultimo_acceso'=>array('tipo'=>'date','valor'=>$valores->lastaccess)
			);
			$usuario=array_merge($usuario,(array)$valores);

			//Insertar array en listado de usuarios
			$usuarios[]=$usuario;
		}
		return $usuarios;
	}
	static public function getLeccionesTotales($courseid){
		global $DB;
		
		//JAMP 171019
		return $DB->count_records('grade_items',array('courseid'=>$courseid,'itemmodule'=>'hvp'))+$DB->count_records('grade_items',array('courseid'=>$courseid,'itemmodule'=>'scorm'));
		//
	}
	
}

class PerformanceTime{

	private $startExecutionTime;
	private $endExecutionTime;

	private $startWallTime;
	private $endWallTime;

	private function rutime($ru, $rus, $index) {
		return ($ru["ru_$index.tv_sec"]*1000 + intval($ru["ru_$index.tv_usec"]/1000))
		-  ($rus["ru_$index.tv_sec"]*1000 + intval($rus["ru_$index.tv_usec"]/1000));
	}

	public function startExecution(){
		$this->startExecutionTime = getrusage();
	}

	public function endExecution(){
		$this->endExecutionTime = getrusage();
	}

	public function startWall(){
		$this->startWallTime = microtime(true); 
	}

	public function endWall(){
		$this->endWallTime = microtime(true); 
	}

	public function printWall(){
		return "Este proceso tomo ".(1000*($time_end - $time_start))."ms en su ejecucion total";
	}

	public function printExecution(){
		return "Este proceso tomo " . $this->rutime($ru, $rustart, "utime") ."ms para su ejecucion, duro " . $this->rutime($ru, $rustart, "stime") ." ms en llamados de sistema<br>";
	}
}

class Reporte{
	private $total_lecciones=array();
	private $puntajes_maximos=array();
	private $courseid=array();
	private $cursos="";

	function __construct($cursos){

		$this->cursos=is_array($cursos)?implode(',', $cursos):$cursos;
//TODO Incluir scorm en reporte
		$this->initParamsSQL(
			"SELECT gi.courseid,
			SUM(gi.grademax) as puntajes_maximos, 
			COUNT(*) as total_lecciones 
			FROM {grade_items} gi 
			WHERE gi.itemmodule='hvp'
			AND gi.courseid IN ($this->cursos)
			GROUP BY gi.courseid			
			");
	}

	private function initParamsSQL($sql){
		global $DB;
		foreach ($DB->get_records_sql($sql) as $index => $registro) {
			foreach ($registro as $campo => $valor) {
				$this->{$campo}[$index]=$valor;
			}
		}
	}

	public function __invoke($departamento=null){
		global $DB;

		$usuarios=OITUtils::getusers($departamento);

		$idusuarios=count($usuarios)==0?'0':implode(',',array_keys($usuarios));

		$sql="SELECT CONCAT(gi.courseid,'_',gg.userid) as id,  
		gg.finalgrade 
		FROM {grade_grades} gg 
		INNER JOIN {grade_items} gi ON gi.id=gg.itemid 
		WHERE gi.itemname = 'Evaluación' AND 
		gg.aggregationstatus='used' AND
		gg.userid IN ($idusuarios) AND
		gi.courseid IN ($this->cursos)";

		$evaluacion=$DB->get_records_sql($sql);

		$sql="SELECT CONCAT(gi.courseid,'_',gg.userid) as id,
		COUNT(*) as diagnostico 
		FROM {grade_grades} gg 
		INNER JOIN {grade_items} gi ON gi.id=gg.itemid 
		WHERE gi.itemname = 'Diagnóstico' AND 
		gg.aggregationstatus='used' AND
		gg.userid IN ($idusuarios) AND
		gi.courseid IN ($this->cursos)
		GROUP BY gg.userid, gi.courseid ";

		$diagnostico=$DB->get_records_sql($sql);

		$sql="SELECT CONCAT(gi.courseid,'_',gg.userid) as id, 
		COUNT(*) as realizadas,
		SUM(gg.finalgrade) as raw
		FROM {grade_grades} gg 
		INNER JOIN {grade_items} gi ON gg.itemid=gi.id 
		WHERE gi.itemmodule='hvp' AND 
		gg.aggregationstatus='used'  AND
		gg.userid IN ($idusuarios) AND
		gi.courseid IN ($this->cursos)
		GROUP BY gg.userid, gi.courseid ";

		$lecciones=$DB->get_records_sql($sql);

		$reporte=array();
		
		foreach ($this->courseid as $curso) {
			$encurso=$pendientes=array();
			$terminaron=array('bien'=>array(),'mal'=>array());

			foreach ($usuarios as $idusuario => $usuario) {

				$id=$curso."_".$idusuario;
				if(!isset($diagnostico[$id])){

					$pendientes[]=$usuario;

				//Revisar que hallan terminado todas las actividades del curso
				}elseif (($this->total_lecciones[$curso]==$lecciones[$id]->realizadas)&&isset($evaluacion[$id])) {

					//Calcular el puntaje total
					$puntajetotal=5*$evaluacion[$id]->finalgrade+50*$lecciones[$id]->raw/$this->puntajes_maximos[$curso];

					//Si el puntaje es mayor que 60 meter al usuario en la subcategoria bien, 
					//de lo contrario en la subcategoria mal
					$terminaron[$puntajetotal>60?'bien':'mal'][]=$usuario;

				//Si no cumple ninguna de las dos condiciones anteriores el usuario esta realizando el curso
				}else{
					$encurso[]=$usuario;
				}
			}

			$reporte[$curso]=array('terminaron'=>$terminaron,'encurso'=>$encurso,'pendientes'=>$pendientes);
		}

		return $reporte;
	}
}
