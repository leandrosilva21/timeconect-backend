# 📧 Guia de Configuração de Email - Time Conect

## ✅ Configuração Concluída

Este documento descreve toda a configuração de email implementada no sistema Time Conect.

## 🔧 Configurações Realizadas

### 1. Variáveis de Ambiente

As seguintes variáveis foram configuradas no arquivo `.env`:

```bash
# ===========================================
# CONFIGURAÇÕES DE EMAIL (ZOHO)
# ===========================================
MAIL_MAILER=smtp
MAIL_HOST=smtp.zoho.com
MAIL_PORT=587
MAIL_USERNAME=naoresponder@timeconect.com.br
MAIL_PASSWORD="cC4pnsx$"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=naoresponder@timeconect.com.br
MAIL_FROM_NAME="Time Conect - Sistema de Gestão"

# URL do Frontend Angular
APP_FRONTEND_URL=http://localhost:4200
```

### 2. Funcionalidades Implementadas

#### 🔐 Recuperação de Senha
- **API Endpoint**: `/api/v1/auth/forgot-password`
- **Método**: POST
- **Parâmetros**: `{ "email": "user@example.com" }`
- **Template**: `resources/views/emails/auth/reset-password.blade.php`
- **Integração**: Compatível com componente `po-page-login` do PO-UI

#### 🎉 Email de Boas-vindas
- **Notificação**: `App\Notifications\WelcomeNotification`
- **Template**: `resources/views/emails/auth/welcome.blade.php`
- **Uso**: Para novos usuários criados no sistema

#### 🔄 Verificação de Token
- **API Endpoint**: `/api/v1/auth/verify-reset-token`
- **Método**: POST
- **Parâmetros**: `{ "email": "user@example.com", "token": "reset_token" }`

#### 🆕 Reset de Senha
- **API Endpoint**: `/api/v1/auth/reset-password`
- **Método**: POST
- **Parâmetros**: 
  ```json
  {
    "email": "user@example.com",
    "token": "reset_token",
    "password": "nova_senha",
    "password_confirmation": "nova_senha"
  }
  ```

### 3. Frontend Angular (PO-UI)

#### Configuração do po-page-login
```typescript
// Configuração da recuperação de senha
recovery: PoPageLoginRecovery = {
  url: '/api/v1/auth/forgot-password',
  type: 'internalLink',
  contactMail: 'suporte@timeconect.com.br'
};

// Literais em português
literals: PoPageLoginLiterals = {
  // ... outras literais
  forgotPassword: 'Esqueci minha senha',
  forgotPasswordPopover: 'Insira seu email para receber um link de recuperação de senha'
};
```

#### Métodos Adicionados ao AuthService
```typescript
// Solicitar recuperação de senha
forgotPassword(email: string): Observable<{ message: string }>

// Verificar token de reset
verifyResetToken(email: string, token: string): Observable<{ valid: boolean; message: string }>

// Redefinir senha
resetPassword(email: string, token: string, password: string, passwordConfirmation: string): Observable<{ message: string }>
```

### 4. Templates de Email

#### 🎨 Design Responsivo
- Design moderno e profissional
- Compatível com dispositivos móveis
- Cores consistentes com a identidade visual
- Ícones e elementos visuais atrativos

#### 📱 Características dos Templates
- **HTML responsivo** com fallbacks para clientes antigos
- **Gradientes CSS** para visual moderno
- **Texto claro** em português brasileiro
- **Call-to-action** destacado
- **Informações de segurança** bem visíveis

### 5. Segurança

#### 🔒 Medidas Implementadas
- Tokens de recuperação com **expiração de 60 minutos**
- **Rate limiting** para tentativas de recuperação
- **Revogação automática** de tokens após uso
- **Criptografia TLS** para envio de emails
- **Validação rigorosa** de dados de entrada

#### 🛡️ Configurações de Segurança
```php
// Configuração no config/auth.php
'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => 'password_reset_tokens',
        'expire' => 60, // minutos
        'throttle' => 60, // segundos entre tentativas
    ],
],
```

## 🧪 Testes

### Comando de Teste
```bash
# Testar email de recuperação de senha
docker-compose exec app php artisan email:test usuario@email.com

# Testar email de boas-vindas
docker-compose exec app php artisan email:test usuario@email.com --type=welcome
```

### Resultados dos Testes
✅ **Email de recuperação**: Enviado com sucesso
✅ **Email de boas-vindas**: Enviado com sucesso
✅ **Configuração SMTP**: Funcionando corretamente
✅ **Templates**: Renderizando corretamente

## 📋 Próximos Passos

### Para Produção
1. **Configurar domínio**: Atualizar `APP_FRONTEND_URL` para domínio de produção
2. **SSL/TLS**: Garantir certificados válidos
3. **Monitoramento**: Implementar logs de email
4. **Backup**: Configurar backup das configurações

### Funcionalidades Adicionais
1. **Notificações de sistema**: Aprovações, rejeições, etc.
2. **Relatórios por email**: Envio automático de relatórios
3. **Newsletter**: Para atualizações do sistema
4. **Duas etapas**: Autenticação por email

## 🔍 Troubleshooting

### Problemas Comuns

#### Email não chega
1. Verificar configurações SMTP no `.env`
2. Verificar logs do Laravel: `storage/logs/laravel.log`
3. Verificar spam/lixo eletrônico
4. Testar com comando: `php artisan email:test`

#### Template não renderiza
1. Verificar sintaxe Blade
2. Verificar permissões de arquivo
3. Limpar cache: `php artisan view:clear`

#### Erro de autenticação SMTP
1. Verificar credenciais do Zoho
2. Verificar se 2FA está habilitado (usar app password)
3. Verificar firewall/proxy

## 📞 Suporte

- **Email de suporte**: suporte@timeconect.com.br
- **Email do sistema**: naoresponder@timeconect.com.br
- **Documentação PO-UI**: https://po-ui.io/documentation/po-page-login

---

**🎉 Configuração de email concluída com sucesso!**

O sistema Time Conect agora possui um sistema completo de emails com:
- Recuperação de senha integrada ao po-page-login
- Templates profissionais em português
- Notificações de boas-vindas
- Segurança robusta
- Facilidade de manutenção
