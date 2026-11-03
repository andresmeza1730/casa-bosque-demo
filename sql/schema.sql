-- ============================================================
-- schema.sql — Casa Bosque · esquema completo
-- ============================================================
-- Generado a partir de la base de PRODUCCIÓN (la base casabosque,
-- volcado del 11/10/2026), que es la fuente de verdad del sistema.
--
-- Este archivo crea la base entera desde cero. Ya NO hay migraciones:
-- las 23 que existían quedaron consolidadas aquí, porque se habían
-- desincronizado del esquema y una instalación nueva salía incompleta.
--
-- CÓMO USARLO
--   phpMyAdmin → selecciona (o crea) tu base → pestaña SQL → pega esto.
--   No incluye "create database" ni "use" a propósito: así se aplica
--   sobre la base que tengas seleccionada y no sobre otra por error.
--
-- QUÉ TRAE
--   - Las 19 tablas, en orden de dependencias.
--   - Los dos disparadores que hacen inmutable la auditoría.
--   - Los datos de catálogo: tipos de habitación, productos, el
--     consecutivo de facturas y un usuario administrador inicial.
--
-- QUÉ NO TRAE, a propósito
--   Nada de datos de operación: ni huéspedes, ni estancias, ni pagos,
--   ni facturas, ni auditoría, ni sesiones, ni enlaces de contraseña.
--   Esos contienen datos personales cifrados, hashes de contraseña y
--   el secreto 2FA del administrador: no deben viajar en un archivo
--   versionado.
--
-- ⚠  TRAS INSTALAR: entra con el administrador inicial y CAMBIA su
--    contraseña de inmediato. La de este archivo es pública.
-- ------------------------------------------------------------

set names utf8mb4;
set sql_mode = 'NO_AUTO_VALUE_ON_ZERO';


-- ============================================================
--  1. Catálogo base
-- ============================================================

create table if not exists tipos_habitacion (
  id            int auto_increment primary key,
  nombre        varchar(50) not null,
  descripcion   varchar(200) null,
  -- 0 significa SIN TOPE de personas. Ver includes/tarifas.php.
  capacidad_max int not null default 2,
  -- Espejo del precio de entrada. Ya no se cobra con ella: se conserva porque
  -- quedan consultas que la leen y no debe decir una mentira.
  tarifa_base   decimal(12,2) null,
  -- EL PRECIO DEPENDE DE CUÁNTAS PERSONAS DUERMAN.
  --   tarifa_1     precio para una persona. NULL = no se vende a una sola a
  --                precio propio, y se cae a tarifa_2.
  --   tarifa_2     precio para dos. NULL = el tipo no admite dos.
  --   tarifa_extra recargo por cada persona a partir de la segunda.
  tarifa_1      decimal(12,2) null,
  tarifa_2      decimal(12,2) null,
  tarifa_extra  decimal(12,2) not null default 0.00,
  created_at    datetime default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists usuarios (
  id            int auto_increment primary key,
  nombre        varchar(100) not null,
  email         varchar(150) not null unique,
  password_hash varchar(255) not null,
  rol           enum('admin','recepcionista') not null default 'recepcionista',
  activo        tinyint(1) not null default 1,
  -- Autenticación en dos pasos (TOTP). El secreto va cifrado (AES-256-GCM)
  -- y los códigos de respaldo como hashes SHA-256 en un JSON.
  twofa_secret  varchar(255) null,
  twofa_enabled tinyint(1) not null default 0,
  twofa_backup  text null,
  created_at    datetime default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists habitaciones (
  id            int auto_increment primary key,
  numero        varchar(10) not null unique,
  piso          int not null,
  tipo_id       int not null,
  capacidad_max int not null default 2,
  tarifa        decimal(12,2) not null,
  -- 'inactiva' = retirada del inventario; no se ofrece para reservas,
  -- pero conserva su historial. Distinto de 'mantenimiento', que es
  -- una pausa temporal.
  estado        enum('disponible','ocupada','limpieza','mantenimiento','inactiva') not null default 'disponible',
  notas         text null,
  created_at    datetime default current_timestamp,
  updated_at    datetime default current_timestamp on update current_timestamp,
  key tipo_id (tipo_id),
  foreign key (tipo_id) references tipos_habitacion(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists productos (
  id          int auto_increment primary key,
  nombre      varchar(100) not null,
  descripcion varchar(200) null,
  precio      decimal(12,2) not null,
  categoria   enum('snack','bebida','servicio','restaurante','otro') not null default 'otro',
  activo      tinyint(1) not null default 1,
  created_at  datetime default current_timestamp
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  2. Huéspedes y su estadía
-- ============================================================

create table if not exists huespedes (
  id                 int auto_increment primary key,
  documento_tipo     enum('CC','CE','PAS','NIT') not null default 'CC',
  -- documento_num y telefono se guardan CIFRADOS (AES-256-GCM, prefijo
  -- "v1:"). documento_hash es el índice ciego (HMAC-SHA256) que permite
  -- buscar y garantizar unicidad sin exponer el dato.
  documento_num      varchar(255) not null,
  documento_hash     char(64) null unique,
  nombres            varchar(100) not null,
  apellidos          varchar(100) not null,
  telefono           varchar(255) null,
  email              varchar(150) null,
  ciudad_origen      varchar(80) null,
  nacionalidad       varchar(60) not null default 'COLOMBIA',
  anonimizado_en     datetime null,          -- borrado seguro (Ley 1581)
  -- Consentimiento de Habeas Data (Ley 1581 de 2012)
  habeas_data        tinyint(1) not null default 0,
  habeas_data_fecha  datetime null,
  habeas_data_origen varchar(20) null,
  created_at         datetime default current_timestamp,
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_hu_created (created_at)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists reservas (
  id              int auto_increment primary key,
  codigo          varchar(12) null unique,     -- código del huésped (CB-XXXXXX)
  habitacion_id   int not null,
  huesped_id      int not null,
  fecha_entrada   date not null,
  fecha_salida    date not null,
  num_personas    int not null default 1,
  hora_llegada    time null,
  estado          enum('confirmada','cancelada','completada','incumplida') not null default 'confirmada',
  notas           text null,
  abono_reserva   decimal(12,2) not null default 0.00,
  metodo_abono    enum('efectivo','transferencia','nequi') null,
  pendiente_noche decimal(12,2) not null default 0.00,
  -- Columnas del pre-check-in en línea, que se retiró del sistema.
  -- Se conservan porque hay reservas históricas que las usaron; el
  -- código ya no las lee. Se pueden eliminar cuando se quiera.
  precheckin       tinyint(1) not null default 0,
  precheckin_en    datetime null,
  pasaporte_num    varchar(255) null,          -- cifrado
  sello_migratorio varchar(40) null,
  firma_habeas     mediumtext null,
  created_at      datetime default current_timestamp,
  usuario_id      int null,
  key habitacion_id (habitacion_id),
  key huesped_id (huesped_id),
  key usuario_id (usuario_id),
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_res_estado_ent (estado, fecha_entrada),
  key idx_res_salida (fecha_salida),
  foreign key (habitacion_id) references habitaciones(id),
  foreign key (huesped_id)    references huespedes(id),
  foreign key (usuario_id)    references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists estancias (
  id               int auto_increment primary key,
  reserva_id       int null,
  habitacion_id    int not null,
  huesped_id       int not null,
  -- Cuánta gente durmió. No entra en el cálculo —`tarifa_noche` ya guarda el
  -- precio resuelto para esta ocupación— pero sin ella el cobro no se podría
  -- explicar: nadie sabría si 90.000 fue «dos camas + 2 adicionales».
  num_personas     int not null default 1,
  fecha_checkin    datetime not null default current_timestamp,
  fecha_checkout   datetime null,
  tarifa_noche     decimal(12,2) not null,
  subtotal_noches  decimal(12,2) default 0.00,
  total_consumos   decimal(12,2) default 0.00,
  total_pagar      decimal(12,2) default 0.00,
  total_abonado    decimal(12,2) not null default 0.00,
  -- Exención de IVA para extranjeros no residentes (Art. 481 E.T.)
  iva_exento       tinyint(1) not null default 0,
  pasaporte_num    varchar(255) null,          -- cifrado
  sello_migratorio varchar(40) null,
  estado           enum('activa','cerrada') not null default 'activa',
  usuario_checkin  int null,
  usuario_checkout int null,
  key reserva_id (reserva_id),
  key habitacion_id (habitacion_id),
  key huesped_id (huesped_id),
  key usuario_checkin (usuario_checkin),
  key usuario_checkout (usuario_checkout),
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_est_estado_out (estado, fecha_checkout),
  foreign key (reserva_id)       references reservas(id),
  foreign key (habitacion_id)    references habitaciones(id),
  foreign key (huesped_id)       references huespedes(id),
  foreign key (usuario_checkin)  references usuarios(id),
  foreign key (usuario_checkout) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Acompañantes y menores de una reserva.
--
-- No es una tabla accesoria: es lo que dispara el control de menores del
-- check-in. Si está vacía, el sistema deja entrar sin verificar Registro
-- Civil ni autorización notarial, y documentar a los menores que se
-- hospedan es obligación legal del hotel (Ley 1098 de 2006 y Ley 679 de
-- 2001 - ESCNNA). Se llena desde Check-in → Acompañantes.
create table if not exists acompanantes (
  id                    int auto_increment primary key,
  reserva_id            int not null,
  estancia_id           int null,
  documento_tipo        enum('RC','TI','CC','CE','PAS') not null default 'CC',
  documento_num         varchar(255) null,     -- cifrado
  documento_hash        char(64) null,
  nombres               varchar(100) not null,
  apellidos             varchar(100) not null,
  fecha_nacimiento      date null,
  es_menor              tinyint(1) not null default 0,
  nacionalidad          varchar(60) not null default 'COLOMBIA',
  parentesco            varchar(40) null,
  registro_civil        varchar(60) null,
  viaja_con             enum('ambos_padres','un_padre','tercero') null,
  responsable1_doc      varchar(255) null,     -- cifrado
  responsable1_nombre   varchar(120) null,
  responsable2_doc      varchar(255) null,     -- cifrado
  responsable2_nombre   varchar(120) null,
  autorizacion_notarial tinyint(1) not null default 0,
  autorizacion_notaria  varchar(120) null,
  opinion_considerada   tinyint(1) not null default 0,
  soporte_archivo       varchar(255) null,
  consentimiento_texto  varchar(255) null,
  docs_ok               tinyint(1) not null default 0,
  docs_ok_por           int null,
  docs_ok_en            datetime null,
  created_at            datetime default current_timestamp,
  key reserva_id (reserva_id),
  key estancia_id (estancia_id),
  key docs_ok_por (docs_ok_por),
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_acom_menor (es_menor, reserva_id),
  foreign key (reserva_id)  references reservas(id),
  foreign key (estancia_id) references estancias(id),
  foreign key (docs_ok_por) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  3. Consumos, pagos y ventas
-- ============================================================

-- Un cargo mal hecho no se borra: se ANULA, con motivo y autor, y deja
-- de sumar a la cuenta. Igual que con las facturas, lo que ocurrió queda
-- registrado; lo que cambia es que deje de tener efecto.
create table if not exists consumos (
  id                 int auto_increment primary key,
  estancia_id        int not null,
  producto_id        int null,
  descripcion_manual varchar(200) null,        -- cargo libre, sin producto
  cantidad           int not null default 1,
  precio_unit        decimal(12,2) not null,
  subtotal           decimal(12,2) not null,
  fecha              datetime default current_timestamp,
  usuario_id         int null,
  -- Anulación del cargo
  anulado_en         datetime null,
  anulado_por        int null,
  motivo_anulacion   varchar(300) null,
  key estancia_id (estancia_id),
  key producto_id (producto_id),
  key usuario_id (usuario_id),
  key anulado_por (anulado_por),
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_con_est_anul (estancia_id, anulado_en),
  foreign key (estancia_id) references estancias(id),
  foreign key (producto_id) references productos(id),
  foreign key (usuario_id)  references usuarios(id),
  constraint consumos_anulado_por_fk foreign key (anulado_por) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists pagos (
  id          int auto_increment primary key,
  estancia_id int not null,
  monto       decimal(12,2) not null,
  metodo      enum('efectivo','tarjeta','transferencia','nequi','otro') not null default 'efectivo',
  nota        varchar(200) null,
  usuario_id  int null,
  fecha       datetime default current_timestamp,
  key estancia_id (estancia_id),
  key usuario_id (usuario_id),
  foreign key (estancia_id) references estancias(id),
  foreign key (usuario_id)  references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Ventas a gente que no está hospedada (mostrador).
create table if not exists ventas_mostrador (
  id               int auto_increment primary key,
  producto_id      int null,
  producto_nombre  varchar(160) not null,      -- copia del nombre al vender
  cantidad         int not null,
  precio_unit      decimal(12,2) not null,
  subtotal         decimal(12,2) not null,
  metodo           enum('efectivo','transferencia','nequi') not null,
  fecha            datetime not null default current_timestamp,
  usuario_id       int null,
  key producto_id (producto_id),
  key usuario_id (usuario_id),
  foreign key (producto_id) references productos(id),
  foreign key (usuario_id)  references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  4. Facturación
-- ============================================================

-- Los datos del receptor van CIFRADOS: una factura lleva documento,
-- dirección, teléfono y correo de una persona identificada.
--
-- Una factura emitida NO se modifica: eso es lo que la hace servir como
-- soporte. Si sale con datos equivocados se ANULA —queda registrada como
-- tal, con motivo, autor y fecha— y se emite una nueva. La original se
-- conserva: no se borra ni se corrige nunca.
--
-- Por eso estancia_id ya no es unique: una estadía puede acumular varias
-- facturas si hubo anulaciones. Lo que el código garantiza es que haya
-- como máximo UNA en estado 'emitida' por estadía.
create table if not exists facturas (
  id               int auto_increment primary key,
  estancia_id      int not null,
  numero           varchar(30) null unique,
  tipo_receptor    enum('natural','juridica') not null,
  documento_tipo   varchar(12) null,
  documento_num    varchar(255) null,          -- cifrado
  razon_social     varchar(180) not null,
  nit              varchar(30) null,
  nit_dv           varchar(2) null,
  direccion        varchar(600) not null,      -- cifrado
  telefono         varchar(120) not null,      -- cifrado
  correo           varchar(400) not null,      -- cifrado
  total            decimal(12,2) not null,
  emitida_en       datetime not null default current_timestamp,
  usuario_id       int null,
  -- Anulación
  estado           enum('emitida','anulada') not null default 'emitida',
  anulada_en       datetime null,
  anulada_por      int null,
  motivo_anulacion varchar(300) null,
  key usuario_id (usuario_id),
  key anulada_por (anulada_por),
  key idx_facturas_estancia_estado (estancia_id, estado),
  foreign key (estancia_id) references estancias(id),
  foreign key (usuario_id)  references usuarios(id),
  constraint facturas_anulada_por_fk foreign key (anulada_por) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


create table if not exists secuencias_factura (
  id     tinyint primary key,
  ultimo int not null default 0
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Facturación electrónica DIAN. Solo se usa si se activa en
-- config/dian.php; mientras tanto el sistema emite su comprobante
-- interno y esta tabla queda vacía.
create table if not exists facturas_electronicas (
  id          int auto_increment primary key,
  estancia_id int not null unique,
  numero      varchar(40) null,                -- prefijo + consecutivo
  cufe        varchar(255) null,               -- Código Único de Factura Electrónica
  qr_data     text null,
  estado      enum('pendiente','emitida','error') not null default 'pendiente',
  respuesta   text null,                       -- respuesta cruda del proveedor
  created_at  datetime default current_timestamp,
  foreign key (estancia_id) references estancias(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  5. Seguridad y trazabilidad
-- ============================================================

create table if not exists auditoria (
  id             int auto_increment primary key,
  tabla_afectada varchar(50) not null,
  registro_id    int null,
  accion         varchar(30) not null,
  detalle        text null,
  -- 4.0.0 (sql/migration_026_auditoria_ampliada.sql): INFO | WARNING | CRITICAL,
  -- el navegador, el SHA-256 de la sesion y el estado antes/despues en JSON
  -- (solo campos de la lista blanca de includes/auditoria.php).
  severidad      varchar(10) not null default 'INFO',
  usuario_id     int null,
  ip_address     varchar(45) null,
  user_agent     varchar(255) null,
  sesion_hash    char(64) null,
  datos_antes    text null,
  datos_despues  text null,
  created_at     datetime default current_timestamp,
  key usuario_id (usuario_id),
  -- indices de rendimiento (ver CHANGELOG 1.0.2)
  key idx_aud_created (created_at),
  key idx_aud_tabla_fec (tabla_afectada, created_at),
  key idx_aud_accion_fec (accion, created_at),
  key idx_aud_sev_fec (severidad, created_at),
  key idx_aud_usr_fec (usuario_id, created_at),
  foreign key (usuario_id) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Control de fuerza bruta en el acceso del personal.
create table if not exists intentos_login (
  id         bigint unsigned auto_increment primary key,
  email      varchar(150) not null,
  ip_address varchar(45) not null,
  exitoso    tinyint(1) not null default 0,
  intento_en datetime not null default current_timestamp,
  key idx_intentos_login_email (email),
  key idx_intentos_login_ip (ip_address),
  key idx_intentos_login_fecha (intento_en),
  key idx_intentos_login_exitoso (exitoso)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- HEREDADA: ningún archivo del sistema la consulta. Se conserva para
-- que el esquema coincida con la base de producción. Se puede eliminar.
create table if not exists intentos_huesped (
  id             bigint unsigned auto_increment primary key,
  documento_hash char(64) null,
  ip_address     varchar(45) not null,
  exitoso        tinyint(1) not null default 0,
  intento_en     datetime not null default current_timestamp,
  key idx_intentos_huesped_doc (documento_hash),
  key idx_intentos_huesped_ip (ip_address),
  key idx_intentos_huesped_fecha (intento_en),
  key idx_intentos_huesped_exitoso (exitoso)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Correos enviados al huésped (factura del check-out).
-- La dirección se guarda cifrada: un listado de correos de clientes es
-- por sí mismo un dato personal.
create table if not exists correos_enviados (
  id          int auto_increment primary key,
  estancia_id int not null,
  tipo        varchar(30) not null default 'factura',
  destino     varchar(400) not null,           -- cifrado
  asunto      varchar(200) not null,
  estado      enum('enviado','error') not null,
  detalle     varchar(400) null,
  usuario_id  int null,
  enviado_en  datetime not null default current_timestamp,
  key usuario_id (usuario_id),
  key idx_correos_estancia (estancia_id),
  key idx_correos_tipo_estado (tipo, estado),
  foreign key (estancia_id) references estancias(id),
  foreign key (usuario_id)  references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Enlaces de un solo uso para restablecer la contraseña.
-- Del token se guarda SOLO su SHA-256: leer esta tabla no da enlaces
-- utilizables.
create table if not exists restablecer_clave (
  id            int auto_increment primary key,
  usuario_id    int not null,
  token_hash    char(64) not null unique,
  solicitado_ip varchar(45) null,
  usado_ip      varchar(45) null,
  expira_en     datetime not null,
  usado_en      datetime null,
  invalidado_en datetime null,
  created_at    datetime not null default current_timestamp,
  key idx_rc_usuario (usuario_id),
  key idx_rc_expira (expira_en),
  key idx_rc_ip_fecha (solicitado_ip, created_at),
  foreign key (usuario_id) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- Sesiones abiertas del personal. Permite cerrarlas al cambiar la
-- contraseña o el 2FA, y verlas desde Seguridad.
-- Del identificador de sesión se guarda SOLO su SHA-256: en claro vale
-- tanto como la contraseña.
create table if not exists sesiones_activas (
  id               int auto_increment primary key,
  usuario_id       int not null,
  sid_hash         char(64) not null unique,
  ip_address       varchar(45) null,
  agente           varchar(255) null,
  creada_en        datetime not null default current_timestamp,
  ultima_actividad datetime not null default current_timestamp,
  cerrada_en       datetime null,
  -- logout | cambio_clave | cambio_2fa | inactividad | manual | admin
  -- desactivada | rol_cambiado | duracion_maxima
  motivo_cierre    varchar(20) null,
  key idx_sesiones_usuario (usuario_id, cerrada_en),
  key idx_sesiones_actividad (ultima_actividad),
  foreign key (usuario_id) references usuarios(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  Equipos de confianza (3.0.0)
-- ============================================================
-- Equipos donde se marcó «Recordar este equipo» al teclear el código del 2FA:
-- en ellos se entra solo con la contraseña durante 14 días. Ver
-- includes/equipos.php y sql/migration_025_equipos_confiables.sql, que explica
-- cada columna.
--
-- `on delete cascade` a propósito: un equipo recordado no es historial, y no
-- debe sumarse a las referencias que impiden borrar a un usuario.
create table if not exists equipos_confiables (
  id          int auto_increment primary key,
  usuario_id  int not null,
  token_hash  char(64) not null unique,
  estado_hash char(64) not null,
  ip_address  varchar(45) null,
  agente      varchar(255) null,
  creado_en   datetime not null default current_timestamp,
  expira_en   datetime not null,
  ultimo_uso  datetime null,
  key idx_equipos_usuario (usuario_id, expira_en),
  foreign key (usuario_id) references usuarios(id) on delete cascade
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;


-- ============================================================
--  6. Auditoría inmutable
-- ============================================================
-- La auditoría no se corrige ni se borra: ese es su valor. Los dos
-- disparadores lo impiden a nivel de base de datos, así que no depende
-- de que la aplicación se comporte bien.

drop trigger if exists auditoria_no_update;
drop trigger if exists auditoria_no_delete;

delimiter $$

create trigger auditoria_no_update before update on auditoria for each row
begin
    signal sqlstate '45000'
        set message_text = 'Los registros de auditoria son inmutables: no se permite UPDATE.';
end$$

create trigger auditoria_no_delete before delete on auditoria for each row
begin
    signal sqlstate '45000'
        set message_text = 'Los registros de auditoria son inmutables: no se permite DELETE.';
end$$

delimiter ;


-- ============================================================
--  7. Datos de catálogo
-- ============================================================

insert into secuencias_factura (id, ultimo) values (1, 0)
  on duplicate key update id = id;

-- Los tipos reales del hotel. El precio depende de la ocupación: ver
-- includes/tarifas.php. En «Dos camas», capacidad_max 0 = sin tope, y tarifa_1
-- nula hace que una persona sola pague igual el precio de dos (se paga la
-- habitación, no la cama).
insert into tipos_habitacion (nombre, descripcion, capacidad_max, tarifa_base, tarifa_1, tarifa_2, tarifa_extra) values
  ('Cama sencilla', 'Una cama sencilla, para una persona',                1, 37000.00, 37000.00,     null,     0.00),
  ('Cama doble',    'Una cama doble, para una o dos personas',            2, 40000.00, 40000.00, 50000.00,     0.00),
  ('Dos camas',     'Dos camas; admite personas adicionales con recargo', 0, 70000.00,     null, 70000.00, 10000.00);

insert into productos (nombre, descripcion, precio, categoria) values
  ('Agua embotellada',       'Botella 600ml',                   3000.00, 'bebida'),
  ('Gaseosa',                'Lata 330ml',                      4000.00, 'bebida'),
  ('Cerveza nacional',       'Botella 330ml',                   6000.00, 'bebida'),
  ('Snack mixto',            'Paquete surtido',                 5000.00, 'snack'),
  ('Chips de plátano',       'Paquete 150g',                    4500.00, 'snack'),
  ('Servicio lavandería',    'Por prenda',                      8000.00, 'servicio'),
  ('Toallas extra',          'Juego de 2 toallas',             10000.00, 'servicio'),
  ('Desayuno buffet',        'Desayuno completo por persona',  25000.00, 'restaurante'),
  ('Parqueadero',            'Por noche',                      15000.00, 'servicio'),
  ('Llamada internacional',  'Por minuto',                      2000.00, 'otro');

-- Las 25 habitaciones del hotel, todas en piso 1: la numeración 1-25 ya no
-- codifica el piso como hacía la vieja (101, 201, 301).
-- tipo_id: 1 = Cama sencilla, 2 = Cama doble, 3 = Dos camas.
insert into habitaciones (numero, piso, tipo_id, capacidad_max, tarifa) values
  ('1',  1, 2, 2, 40000.00), ('2',  1, 2, 2, 40000.00), ('3',  1, 2, 2, 40000.00),
  ('4',  1, 2, 2, 40000.00), ('5',  1, 2, 2, 40000.00), ('6',  1, 2, 2, 40000.00),
  ('7',  1, 2, 2, 40000.00), ('8',  1, 1, 1, 37000.00), ('9',  1, 1, 1, 37000.00),
  ('10', 1, 1, 1, 37000.00), ('11', 1, 1, 1, 37000.00), ('12', 1, 1, 1, 37000.00),
  ('13', 1, 2, 2, 40000.00), ('14', 1, 2, 2, 40000.00), ('15', 1, 2, 2, 40000.00),
  ('16', 1, 3, 0, 70000.00), ('17', 1, 1, 1, 37000.00), ('18', 1, 2, 2, 40000.00),
  ('19', 1, 2, 2, 40000.00), ('20', 1, 1, 1, 37000.00), ('21', 1, 3, 0, 70000.00),
  ('22', 1, 3, 0, 70000.00), ('23', 1, 3, 0, 70000.00), ('24', 1, 2, 2, 40000.00),
  ('25', 1, 3, 0, 70000.00);

-- (Esta copia pública NO trae el administrador de fábrica que trae el esquema original: las cuentas las crea el generador
--  de datos de demostración, sql/demo/generar_demo.php, con claves al azar.)
