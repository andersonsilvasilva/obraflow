import { describe, expect, it } from 'vitest'
import { applyMask, formatPhone, isCnpj, isCpf, maskCep, maskCnpj, maskCpf, maskPhone, validateMask } from './masks'

describe('máscaras progressivas', () => {
  it('CPF, CNPJ e CEP formatam conforme digita e ignoram não dígitos', () => {
    expect(maskCpf('490')).toBe('490')
    expect(maskCpf('4901')).toBe('490.1')
    expect(maskCpf('52998224725')).toBe('529.982.247-25')
    expect(maskCpf('529.982.247-25999')).toBe('529.982.247-25') // corta o excesso
    expect(maskCnpj('11222333000181')).toBe('11.222.333/0001-81')
    expect(maskCnpj('11a222')).toBe('11.222')
    expect(maskCep('78000000')).toBe('78000-000')
  })

  it('telefone: fixo com 10 dígitos, celular com 11', () => {
    expect(maskPhone('6533221100')).toBe('(65) 3322-1100')
    expect(maskPhone('65999887766')).toBe('(65) 99988-7766')
    expect(maskPhone('65')).toBe('(65')
    expect(formatPhone('65999887766')).toBe('(65) 99988-7766')
    expect(formatPhone(null)).toBe('—')
  })

  it('CPF/CNPJ segue o tipo de pessoa; sem tipo, decide pelo tamanho', () => {
    expect(applyMask('cpf_cnpj', '11222333000181', 'person')).toBe('112.223.330-00') // pessoa física: corta em 11
    expect(applyMask('cpf_cnpj', '11222333000181', 'company')).toBe('11.222.333/0001-81')
    expect(applyMask('cpf_cnpj', '52998224725')).toBe('529.982.247-25')
    expect(applyMask('cpf_cnpj', '112223330001')).toBe('11.222.333/0001')
  })

  it('valor mascarado pela API (D-P21) não é alterado', () => {
    expect(applyMask('cpf', '***.982.247-**')).toBe('***.982.247-**')
    expect(validateMask('cpf', '***.982.247-**')).toBe(true)
  })
})

describe('validação na tela', () => {
  it('dígitos verificadores de CPF e CNPJ', () => {
    expect(isCpf('529.982.247-25')).toBe(true)
    expect(isCpf('529.982.247-26')).toBe(false)
    expect(isCpf('111.111.111-11')).toBe(false)
    expect(isCnpj('11.222.333/0001-81')).toBe(true)
    expect(isCnpj('11.444.777/0001-61')).toBe(true)
    expect(isCnpj('11.222.333/0001-82')).toBe(false)
  })

  it('mensagens por tipo; vazio é válido (obrigatoriedade é outra regra)', () => {
    expect(validateMask('cpf', '490')).toBe('CPF inválido.')
    expect(validateMask('cpf_cnpj', '52998224725', 'company')).toBe('CNPJ inválido.')
    expect(validateMask('cpf_cnpj', '52998224725', 'person')).toBe(true)
    expect(validateMask('phone', '(65) 9999')).toBe('Telefone deve ter DDD + 8 ou 9 dígitos.')
    expect(validateMask('phone', '(65) 99988-7766')).toBe(true)
    expect(validateMask('cep', '78000')).toBe('CEP deve ter 8 dígitos.')
    expect(validateMask('cnpj', '')).toBe(true)
  })
})
