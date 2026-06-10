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
    ->getSetting('empire_hero_motion', 'empire_theme') ?: 'poster';
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
      'poster' => t('Still poster (recommended) — the featured artwork; the video loads only on the watch page, after the visitor consents to YouTube.'),
      'autoplay' => t('Autoplay preview — a muted, looping, chrome-less preview of the featured video plays in the homepage hero.'),
    ],
    '#description' => t('“Autoplay preview” embeds and plays YouTube in the homepage hero <em>before</em> the visitor has consented to it, which diverges from Empire’s privacy-first default (SPEC §19 consent / §24 no-autoplay) and may carry consent/compliance obligations in some regions — enable it only if that is appropriate for your audience. The preview is always muted, looping and chrome-less, respects the visitor’s “reduce motion” setting, and falls back to the still poster wherever playback is blocked.'),
  ];
}
