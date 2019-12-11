<?php
require_once($CFG->dirroot."/course/format/renderer.php");
require_once($CFG->dirroot."/oit/lib/utils.php");

class OITRenderer extends format_topics_renderer{
    function __construct(format_topics_renderer $obj = null){
        if($obj===null){
            return;
        }
        $objvalues=get_object_vars($obj);
        foreach ($objvalues as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Imprime Leciones y resumen del curso definido
     * @param  object $course    Objeto del curso que se quiere imprimir
     */
    public function print_course($course){
        global $USER,$CFG,$DB;
        $listadoLecciones="";

        $fullname= OITUtils::normalize($course->fullname);

        $plantillaLeccion= file_get_contents($CFG->dirroot."/oit/plantillas/cajaleccion.html");

        $grades=OITUtils::getgrades($USER->id)[$course->id];

        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_section_info_all() as $section => $thissection) {
            $section=$modinfo->get_section_info($thissection->section)->section;
            foreach ($modinfo->sections[$section] as $key => $value) {
                $mod=$modinfo->cms[$value];
                if($mod->modname=='hvp'){

                    $puntaje=$grades[$mod->modname.$mod->instance]['puntaje'];
                    $aprobo=$grades[$mod->modname.$mod->instance]['aprobo'];


                    $ultimaModificacion=userdate($DB->get_record('hvp',array('name'=>$mod->name),'timemodified')->timemodified,'%e de %B del %Y');

                    $nombreCompletar=$DB->get_record('grade_items',array('id' => json_decode($mod->availability)->c[0]->id),'itemname')->itemname;

                    $tooltip="No disponible hasta que se realize el <strong>$nombreCompletar</strong>.";

                    $estado='ico_entrar';

                    if($puntaje!==null || !$mod->available){
                        if($mod->available){
                            $estado.=$aprobo?'_bien':'_mal';
                        }else{
                            $estado.='_bloq';
                        }
                    }

                    $leccion= array(
                        'titulo'=>$mod->name,
                        'descripcion'=>$mod->content,
                        'ultimaModificacion'=>$ultimaModificacion,
                        'url'=>$mod->available?$mod->url:$mod->url, //Cambiar condicion para bloquear click
                        'puntaje'=>$puntaje!==null?$puntaje:'void',
                        'tooltip'=>$mod->available?'':$tooltip,
                        'id'=>$mod->available?'':"leccion_".rand(),
                        'estado'=>$estado
                    );

                    $listadoLecciones.=OITUtils::plantillarender($plantillaLeccion,$leccion);
                }elseif($mod->modname=='forum'&&$mod->name=="Preguntas o comentarios sobre el curso actual"){
                    $ultimaModificacion=userdate($DB->get_record('hvp',array('name'=>$mod->name),'timemodified')->timemodified,'%e de %B del %Y');

                    $leccion= array(
                        'titulo'=>$mod->name,
                        'descripcion'=>$mod->content,
                        'ultimaModificacion'=>$ultimaModificacion,
                        'estado'=>$estado,
                        'puntaje'=>'void',
                        'url'=>$mod->available?$mod->url:$mod->url
                    );

                    $listadoLecciones.=OITUtils::plantillarender($plantillaLeccion,$leccion);
                }
            }
        }



        $numeroPersonaje=($course->category%12)==0?1:($course->category%12);
        $puntaje=array(
            'respuestas_correctas'=>0,
            'estado'=>"",
            'personaje'=>"/oit/img/footer/$numeroPersonaje/footer_menu_per.png",
            'lecciones_terminadas'=>0,
            'respuestas_totales'=>0,
            'puntaje_total'=>0
        );

        if(isset($grades['puntaje'])){

            $estado=$grades['puntaje_max']/2>$grades['puntaje']?'mal':'bien';

            $puntaje['respuestas_correctas']=$grades['puntaje'];
            $puntaje['estado']=$estado;
            $puntaje['personaje']="/oit/img/footer/$numeroPersonaje/footer_{$estado}_per.png";
            $puntaje['lecciones_terminadas']=$grades['aprobadas']+$grades['reprobadas'];
            $puntaje['respuestas_totales']=$grades['puntaje_max'];
            $puntaje['puntaje_total']=round(50*$grades['puntaje']/$grades['puntaje_max']);

        }

        $plantillaResumen=file_get_contents($CFG->dirroot."/oit/plantillas/explicacionpuntos.html");
        $resumenPuntaje=OITUtils::plantillarender($plantillaResumen,$puntaje);

        $javascript="setBootstrapTooltips({html:true});";

        $style=".oit-container .page-header-headings{
            margin-bottom:0;
        }";
        echo html_writer::tag('style',$style);
        echo html_writer::tag('div',$listadoLecciones);
        echo $resumenPuntaje;
        echo html_writer::tag('script',$javascript);
    }

}
