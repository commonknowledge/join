import { Page } from '@playwright/test';
import { CONTINUE } from '../helpers';
import { expect, getSettings, loadLegacySettings, openJoinForm, test } from './fixtures';

/**
 * Settings page, Theme tab: the join form's colours and custom CSS.
 *
 * The plugin writes the colours as CSS variables on the join form's page, and
 * the form's stylesheet uses them.
 */

/** A CSS custom property's value on the page's root element. */
const cssVariable = (page: Page, name: string) =>
  page.evaluate((property) => getComputedStyle(document.documentElement).getPropertyValue(property).trim(), name);

/** A computed style property of the first element matching `selector`. */
const computed = (page: Page, selector: string, property: string) =>
  page
    .locator(selector)
    .first()
    .evaluate((element, name) => getComputedStyle(element).getPropertyValue(name), property);

test('the colours have defaults before anything is saved', async ({ page }) => {
  expect(getSettings(['theme_primary_color', 'theme_gray_color', 'theme_background_color'])).toEqual({
    theme_primary_color: '#007bff',
    theme_gray_color: '#dfdcda',
    theme_background_color: '#f4f1ee',
  });

  await openJoinForm(page);
  expect(await cssVariable(page, '--ck-join-form-primary-color')).toBe('#007bff');
  expect(await cssVariable(page, '--ck-join-form-gray-color')).toBe('#dfdcda');
  expect(await cssVariable(page, '--ck-join-form-background-color')).toBe('#f4f1ee');
});

test("Primary Color colours the form's buttons", async ({ settings, page }) => {
  await settings.open('Theme');
  await settings.setColor('Primary Color', '#336699');
  await settings.save();

  expect(getSettings(['theme_primary_color']).theme_primary_color).toMatch(/^#336699$/i);

  await openJoinForm(page);
  expect((await cssVariable(page, '--ck-join-form-primary-color')).toLowerCase()).toBe('#336699');
  expect(await computed(page, CONTINUE, 'background-color')).toBe('rgb(51, 102, 153)');
});

test("Gray Color and Background Color reach the form's styles", async ({ settings, page }) => {
  await settings.open('Theme');
  await settings.setColor('Gray Color', '#aabbcc');
  await settings.setColor('Background Color', '#fafafa');
  await settings.save();

  const saved = getSettings(['theme_gray_color', 'theme_background_color']);
  expect(saved.theme_gray_color).toMatch(/^#aabbcc$/i);
  expect(saved.theme_background_color).toMatch(/^#fafafa$/i);

  await openJoinForm(page);
  expect((await cssVariable(page, '--ck-join-form-gray-color')).toLowerCase()).toBe('#aabbcc');
  expect((await cssVariable(page, '--ck-join-form-background-color')).toLowerCase()).toBe('#fafafa');
});

test("Custom Css is added to the join form's page", async ({ settings, page }) => {
  const css = '.ck-join-flow { outline: 3px solid rgb(255, 0, 0); }';
  await settings.open('Theme');
  await settings.setText('Custom Css', css);
  await settings.save();

  expect(getSettings(['custom_css']).custom_css).toBe(css);

  await openJoinForm(page);
  expect(await computed(page, '.ck-join-flow', 'outline')).toBe('rgb(255, 0, 0) solid 3px');
});

test.describe('Theme saved before the settings page was rebuilt', () => {
  // See tests/settings/legacy/README.md.
  test('is still read and still styles the form', async ({ page }) => {
    loadLegacySettings('theme');

    expect(getSettings(['theme_primary_color', 'theme_gray_color', 'theme_background_color', 'custom_css'])).toEqual({
      theme_primary_color: '#336699',
      theme_gray_color: '#AABBCC',
      theme_background_color: '#FAFAFA',
      custom_css: '.ck-join-flow { outline: 3px solid rgb(255, 0, 0); }',
    });

    await openJoinForm(page);
    expect(await computed(page, CONTINUE, 'background-color')).toBe('rgb(51, 102, 153)');
    expect(await computed(page, '.ck-join-flow', 'outline')).toBe('rgb(255, 0, 0) solid 3px');
  });
});
