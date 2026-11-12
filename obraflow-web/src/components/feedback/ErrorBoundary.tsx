import { Component, type ErrorInfo, type ReactNode } from 'react'
import { ErrorState } from './states'

interface Props {
  children: ReactNode
}
interface State {
  error: Error | null
}

/** Captura erros de renderização para não derrubar toda a aplicação. */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Erro de renderização', error, info.componentStack)
  }

  render() {
    if (this.state.error) {
      return <ErrorState error={this.state.error} onRetry={() => this.setState({ error: null })} />
    }
    return this.props.children
  }
}
