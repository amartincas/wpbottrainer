<?php

namespace App\ExerciseCatalog\Exceptions;

/**
 * Hito 9.3 — el proveedor falló a mitad de una sincronización COMPLETA
 * del catálogo. Señal deliberada, nunca silenciada: permite a
 * ExerciseImporter::fullSync() detenerse sin reconciliar bajas sobre una
 * corrida incompleta.
 */
class ProviderSyncException extends \RuntimeException {}
