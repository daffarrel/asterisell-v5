<?php

/* * ************************************************************
  !!!                                                        !!!
  !!! WARNING: This file is automatic generated.             !!!
  !!!                                                        !!!
  !!! In order to modify this file change the content of     !!!
  !!!                                                        !!!
  !!!    /module_template/call_report_template               !!!
  !!!                                                        !!!
  !!! and execute                                            !!!
  !!!                                                        !!!
  !!!    sh generate_modules.sh                              !!!
  !!!                                                        !!!
 * ************************************************************ */

$defaultChoice = "";
if (isset($filters['filter_on_dst_geographic_location'])) {
    $defaultChoice = $filters['filter_on_dst_geographic_location'];
}
echo select_tag('filters[filter_on_dst_geographic_location]', options_for_select(VariableFrame::$filterOnGeographicLocation, $defaultChoice));
?>