<?php

namespace App\Training\Enums;

/**
 * Hito Provider-Agnostic Normalization — vocabulario cerrado de dominio
 * para "qué clase de ejercicio es esto" (fuerza, cardio, movilidad...),
 * análogo a `Equipment`/`MuscleFocus`: cada `ExerciseNormalizerInterface`
 * lo usa para traducir el vocabulario crudo de SU proveedor, nunca al
 * revés — este enum no conoce ningún proveedor concreto.
 *
 * Los 15 valores fueron confirmados contra el catálogo en vivo del
 * proveedor principal en producción (ver docs/DECISIONS.md, Audit #4) —
 * mismos strings que ese proveedor ya usa (alias 1:1), pero como
 * vocabulario PROPIO: un valor crudo que no coincida con ninguno de estos
 * se descarta en el normalizer del proveedor (nunca se inventa un caso
 * nuevo aquí solo para "no perder el dato").
 *
 * Deliberadamente SIN consumidor en TrainingEngine todavía — es metadato
 * de dominio para catálogo/curación (ver docs/DECISIONS.md, Audit #3/#4:
 * un ejercicio de fuerza real puede tener `exercise_type=[]` si el
 * proveedor no lo etiquetó, así que "no contiene strength" no puede
 * tratarse como "no es fuerza" sin una revisión humana). Convertir esto en
 * un filtro de selección es una decisión de producto futura, explícitamente
 * fuera de alcance de este hito.
 */
enum ExerciseType: string
{
    case Strength = 'strength';
    case Balance = 'balance';
    case Functional = 'functional';
    case Core = 'core';
    case Mobility = 'mobility';
    case Cardio = 'cardio';
    case Calisthenics = 'calisthenics';
    case Stretching = 'stretching';
    case Yoga = 'yoga';
    case Plyometric = 'plyometric';
    case Isometric = 'isometric';
    case Warmup = 'warmup';
    case Rehabilitation = 'rehabilitation';
    case Hiit = 'hiit';
    case Cooldown = 'cooldown';
}
