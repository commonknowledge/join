import { useCallback, useEffect, useState } from "react"

export interface PageState {
    stage: 'enter-details' | 'plan' | 'payment-method' | 'payment-details' | 'confirm'
}

export interface StateRouter {
	state: PageState
	setState: (state: PageState) => void
}

/**
 * Super-simple router to allow back-navigation between form stages
 */
export const useStateRouter = (value: PageState): StateRouter => {
	const [state, setStateValue] = useState(() => window.history.state ?? value)
	useEffect(() => {
		const onPopState = (event: PopStateEvent) => {
			resetScroll()
			setTimeout(() => {
				setStateValue(event.state ?? value)
			})
		}

		window.addEventListener('popstate', onPopState)
		return () => window.removeEventListener('popstate', onPopState)
	}, [value])

	const setState = useCallback((newState) => {
		resetScroll()
		window.history.pushState(newState, document.title)
		setStateValue(newState)
	}, [setStateValue])

	return {
		state,
		setState
	}
}

const resetScroll = () => {
	window.scrollTo({
		left: 0,
		top: 0,
	})
}

export const stripUrlParams = () => {
  window.history.replaceState(window.history.state, document.title, window.location.href.replace(/\?.*/, ''))
}