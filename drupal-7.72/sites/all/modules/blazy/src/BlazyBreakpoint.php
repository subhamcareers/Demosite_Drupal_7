<?php

namespace Drupal\blazy;

use Drupal\blazy\Utility\NestedArray;

/**
 * Implements BlazyBreakpointInterface.
 *
 * This file is not functional or used anywhere, yet.
 *
 * @todo TBD; for keeping or removal at blazy:7.x-2.0.
 * @see https://www.drupal.org/node/3105243
 */
class BlazyBreakpoint implements BlazyBreakpointInterface {

  /**
   * Provides re-usable breakpoint data-attributes for IMG or DIV element.
   *
   * $settings['breakpoints'] must contain: xs, sm, md, lg breakpoints with
   * the expected keys: width, image_style.
   */
  public static function attributes(array &$attributes, array &$settings, $item = NULL) {
    // Only provide multi-serving image URLs if breakpoints are provided.
    if (empty($settings['breakpoints'])) {
      return;
    }

    $srcset = $json = [];
    // https://css-tricks.com/sometimes-sizes-is-quite-important/
    // For older iOS devices that don't support w descriptors in srcset, the
    // first source item in the list will be used.
    $settings['breakpoints'] = array_reverse($settings['breakpoints']);
    foreach ($settings['breakpoints'] as $key => $breakpoint) {
      $url = image_style_url($breakpoint['image_style'], $settings['uri']);

      // Supports multi-breakpoint aspect ratio with irregular sizes.
      // Yet, only provide individual dimensions if not already set.
      // See Drupal\blazy\BlazyFormatter::setImageDimensions().
      if (!empty($settings['_breakpoint_ratio']) && empty($settings['blazy_data']['dimensions'])) {
        $dimensions = Blazy::transformDimensions($breakpoint['image_style'], $item);

        if ($width = self::widthFromDescriptors($breakpoint['width'])) {
          $json[$width] = round((($dimensions['height'] / $dimensions['width']) * 100), 2);
        }
      }

      $settings['breakpoints'][$key]['url'] = $url;

      // Recheck library if multi-styled BG is still supported anyway.
      // Confirmed: still working with GridStack multi-image-style per item.
      if ($settings['background']) {
        $attributes['data-src-' . $key] = $url;
      }
      else {
        $width = trim($breakpoint['width']);
        $width = is_numeric($width) ? $width . 'w' : $width;
        $srcset[] = $url . ' ' . $width;
      }
    }

    if ($srcset) {
      $settings['srcset'] = implode(', ', $srcset);

      $attributes['srcset'] = '';
      $attributes['data-srcset'] = $settings['srcset'];
      $attributes['sizes'] = '100w';

      if (!empty($settings['sizes'])) {
        $attributes['sizes'] = trim($settings['sizes']);
        unset($attributes['height'], $attributes['width']);
      }
    }

    if ($json) {
      $settings['blazy_data']['dimensions'] = $json;
    }
  }

  /**
   * Gets the numeric "width" part from a descriptor.
   */
  public static function widthFromDescriptors($descriptor = '') {
    if (empty($descriptor)) {
      return FALSE;
    }

    // Dynamic multi-serving aspect ratio with backward compatibility.
    $descriptor = trim($descriptor);
    if (is_numeric($descriptor)) {
      return (int) $descriptor;
    }

    // Cleanup w descriptor to fetch numerical width for JS aspect ratio.
    $width = strpos($descriptor, "w") !== FALSE ? str_replace('w', '', $descriptor) : $descriptor;

    // If both w and x descriptors are provided.
    if (strpos($descriptor, " ") !== FALSE) {
      // If the position is expected: 640w 2x.
      list($width, $px) = array_pad(array_map('trim', explode(" ", $width, 2)), 2, NULL);

      // If the position is reversed: 2x 640w.
      if (is_numeric($px) && strpos($width, "x") !== FALSE) {
        $width = $px;
      }
    }
    return is_numeric($width) ? (int) $width : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function cleanUpBreakpoints(array &$settings = []) {
    if (!empty($settings['breakpoints'])) {
      $breakpoints = array_filter(array_map('array_filter', $settings['breakpoints']));

      $settings['breakpoints'] = NestedArray::filter($breakpoints, function ($breakpoint) {
        return !(is_array($breakpoint) && (empty($breakpoint['width']) || empty($breakpoint['image_style'])));
      });
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function buildDataBlazy(array &$settings, $item = NULL) {
    // Identify that Blazy can be activated by breakpoints, regardless results.
    $settings['blazy'] = TRUE;

    // Bail out if already defined at BlazyFormatter::setImageDimensions().
    // Blazy doesn't always deal with image formatters, see self::isBlazy().
    if (!empty($settings['blazy_data'])) {
      return;
    }

    // May be set at BlazyFormatter::setImageDimensions() if using formatters,
    // yet not set from non-formatters like views fields, see self::isBlazy().
    Blazy::imageDimensions($settings, $item, TRUE);

    $json = $sources = $styles = [];
    $end = end($settings['breakpoints']);

    // Check for cropped images at the 5 given styles before any hard work
    // Ok as run once at the top container regardless of thousand of images.
    foreach ($settings['breakpoints'] as $key => $breakpoint) {
      if (blazy()->isCrop($breakpoint['image_style'])) {
        $styles[$key] = TRUE;
      }
    }

    // Bail out if not all images are cropped at all breakpoints.
    // The site builder just don't read the performance tips section.
    if (count($styles) != count($settings['breakpoints'])) {
      return;
    }

    // We have all images cropped here.
    foreach ($settings['breakpoints'] as $key => $breakpoint) {
      if ($width = self::widthFromDescriptors($breakpoint['width'])) {
        // If contains crop, sets dimension once, and let all images inherit.
        if (!empty($settings['ratio'])) {
          $dimensions = Blazy::transformDimensions($breakpoint['image_style'], $item);
          $padding = round((($dimensions['height'] / $dimensions['width']) * 100), 2);

          $json['dimensions'][$width] = $padding;

          // Only set padding-bottom for the last breakpoint to avoid FOUC.
          if ($end['width'] == $breakpoint['width']) {
            $settings['padding_bottom'] = $padding;
          }
        }

        // If BG, provide [data-src-BREAKPOINT], regardless uri or ratio.
        if (!empty($settings['background'])) {
          $sources[] = ['width' => (int) $width, 'src' => 'data-src-' . $key];
        }
      }
    }

    // As of Blazy v1.6.0 applied to BG only.
    if ($sources) {
      $json['breakpoints'] = $sources;
    }

    // Supported modules can add blazy_data as [data-blazy] to the container.
    // This also informs individual images to not work with dimensions any more
    // as _all_ breakpoint image styles contain 'crop'.
    if ($json) {
      $settings['blazy_data'] = $json;
    }
  }

}
