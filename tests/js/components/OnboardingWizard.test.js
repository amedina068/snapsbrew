import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import OnboardingWizard from '../../../resources/js/components/OnboardingWizard.vue'

let http
let assign

beforeEach(() => {
  http = { post: vi.fn() }
  vi.stubGlobal('axios', http)
  assign = vi.fn()
  vi.stubGlobal('location', { assign })
})

const mountAtInvites = async () => {
  const wrapper = mount(OnboardingWizard, { props: { initialName: 'Ana', initialCompany: 'Acme' } })
  await wrapper.setData({ step: 3 })
  await wrapper.get('#ob-invite-0').setValue('pat@example.com')
  return wrapper
}

const clickButton = async (wrapper, text) => {
  const button = wrapper.findAll('button').find(b => b.text() === text)
  await button.trigger('click')
  await flushPromises()
}

describe('OnboardingWizard invites', () => {
  it('shows the invite links and waits when mail is not set up', async () => {
    http.post.mockImplementation(url => url === '/onboarding/invites'
      ? Promise.resolve({ data: { ok: true, mail_configured: false, invitations: [{ email: 'pat@example.com', url: 'https://x.test/register?invitation=abc' }] } })
      : Promise.resolve({ data: { redirect: '/dashboard' } }))
    const wrapper = await mountAtInvites()

    await clickButton(wrapper, 'Finish')

    expect(wrapper.text()).toContain('Mail is not set up. Send this link yourself.')
    expect(wrapper.text()).toContain('https://x.test/register?invitation=abc')
    expect(http.post).not.toHaveBeenCalledWith('/onboarding/finish', {})
    expect(assign).not.toHaveBeenCalled()

    await clickButton(wrapper, 'Go to the dashboard')

    expect(http.post).toHaveBeenCalledWith('/onboarding/finish', {})
    expect(assign).toHaveBeenCalledWith('/dashboard')
  })

  it('goes to the dashboard at once when mail is set up', async () => {
    http.post.mockImplementation(url => url === '/onboarding/invites'
      ? Promise.resolve({ data: { ok: true, mail_configured: true, invitations: [{ email: 'pat@example.com', url: 'https://x.test/r' }] } })
      : Promise.resolve({ data: { redirect: '/dashboard' } }))
    const wrapper = await mountAtInvites()

    await clickButton(wrapper, 'Finish')

    expect(wrapper.text()).not.toContain('Send this link yourself')
    expect(assign).toHaveBeenCalledWith('/dashboard')
  })
})

describe('OnboardingWizard profile', () => {
  it('asks the founder for the company name and sends it', async () => {
    http.post.mockResolvedValue({ data: { ok: true } })
    const wrapper = mount(OnboardingWizard, { props: { initialName: 'Ana', initialCompany: 'Acme' } })

    expect(wrapper.find('#ob-company').exists()).toBe(true)
    await clickButton(wrapper, 'Continue')

    expect(http.post).toHaveBeenCalledWith('/onboarding/profile', { name: 'Ana', company_name: 'Acme' })
  })

  it('does not show or send the company name for an invited member', async () => {
    http.post.mockResolvedValue({ data: { ok: true } })
    const wrapper = mount(OnboardingWizard, {
      props: { initialName: 'Pat', initialCompany: 'Acme', canRenameCompany: false },
    })

    expect(wrapper.find('#ob-company').exists()).toBe(false)
    expect(wrapper.text()).toContain('You joined Acme.')
    await clickButton(wrapper, 'Continue')

    expect(http.post).toHaveBeenCalledWith('/onboarding/profile', { name: 'Pat' })
  })
})

describe('OnboardingWizard for a member', () => {
  it('has two steps and finishes after the categories, with no invite step', async () => {
    http.post.mockImplementation(url => url === '/onboarding/finish'
      ? Promise.resolve({ data: { redirect: '/dashboard' } })
      : Promise.resolve({ data: { ok: true } }))
    const wrapper = mount(OnboardingWizard, {
      props: { initialName: 'Pat', initialCompany: 'Acme', canRenameCompany: false, canInvite: false },
    })

    expect(wrapper.text()).toContain('Step 1 of 2')
    await clickButton(wrapper, 'Continue')
    await clickButton(wrapper, wrapper.findAll('button[aria-pressed]')[0].text())
    await clickButton(wrapper, 'Finish')

    expect(wrapper.find('#ob-invite-0').exists()).toBe(false)
    expect(http.post).not.toHaveBeenCalledWith('/onboarding/invites', expect.anything())
    expect(http.post).toHaveBeenCalledWith('/onboarding/finish', {})
    expect(assign).toHaveBeenCalledWith('/dashboard')
  })
})
