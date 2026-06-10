<?php

/**
 * @file
 * Theme settings for the Empire theme.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function empire_theme_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $motion = \Drupal::service('Drupal\Core\Extension\ThemeSettingsProvider')
    ->getSetting('empire_hero_motion', 'empire_theme') ?: 'autoplay';
  $form['empire_hero'] = [
    '#type' => 'details',
    '#title' => t('Featured hero'),
    '#open' => TRUE,
    '#weight' => -10,
  ];
  $form['empire_hero']['empire_hero_motion'] = [
    '#type' => 'radios',
    '#title' => t('Hero motion'),
    '#default_value' => $motion,
    '#options' => [
      'autoplay' => t('Autoplay preview (default) — a muted, looping, chrome-less preview of the featured video plays in the homepage hero, but only after the visitor consents to YouTube.'),
      'poster' => t('Still poster — the featured artwork only; the video loads on the watch page after the visitor consents to YouTube.'),
    ],
    '#description' => t('The autoplay preview is consent-gated: it never loads YouTube until the visitor consents (Klaro), so the privacy-first default (no external content before consent) is preserved. It is always muted, looping and chrome-less, respects the visitor’s “reduce motion” setting, and falls back to the still poster wherever playback is blocked or not yet consented.'),
  ];
}
