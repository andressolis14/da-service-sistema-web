<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * Constantes de rol, con el mismo id que la tabla 'rol' de da_service_bd.sql.
 * Todo control de acceso compara por este id, nunca por el nombre del rol
 * escrito a mano (eso fue lo que causó el defecto de 'técnico' en RNF-01).
 */

const ROL_EMPLEADO      = 1;
const ROL_JEFE           = 2;
const ROL_CONTABILIDAD   = 3;
const ROL_TECNICO        = 4;
const ROL_COORDINADOR    = 5;
const ROL_VENDEDOR       = 6;
const ROL_GERENTE        = 7;
const ROL_ADMINISTRADOR  = 8;
