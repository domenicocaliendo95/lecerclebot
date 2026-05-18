import { useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import axios from 'axios';

import { auth } from '@/lib/api';

export default function Login() {
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(false);

  const cleanPhone = phone.replace(/\D/g, '');
  const canContinue = cleanPhone.length >= 9 && !loading;

  async function handleContinue() {
    if (!canContinue) return;
    setLoading(true);
    try {
      const fullPhone = '+39' + cleanPhone;
      const res = await auth.requestOtp(fullPhone);
      router.push({
        pathname: '/(auth)/verify',
        params: {
          phone: fullPhone,
          maskedPhone: res.masked_phone,
          expiresAt: res.expires_at,
        },
      });
    } catch (err) {
      const message = axios.isAxiosError(err)
        ? err.response?.data?.error?.message ?? 'Errore di rete. Riprova.'
        : 'Errore inatteso.';
      Alert.alert('Ops', message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg">
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        className="flex-1"
      >
      <ScrollView
        contentContainerStyle={{ flexGrow: 1 }}
        keyboardShouldPersistTaps="handled"
        showsVerticalScrollIndicator={false}
      >
      <View className="flex-1 px-7 pt-6">
        {/* Brand */}
        <View className="items-center mt-12 mb-14">
          <Text className="font-display-bold text-[36px] tracking-[3.6px] text-ink dark:text-cream">
            LE CERCLE
          </Text>
          <Text className="font-script text-[36px] -mt-2 text-ocra-dark dark:text-ocra">
            Tennis Club
          </Text>
        </View>

        {/* Greeting */}
        <View className="mb-9">
          <Text className="font-display-italic text-[32px] text-ink dark:text-cream leading-[34px]">
            Bentornato.
          </Text>
          <Text className="font-body-medium text-[14px] text-ink-muted mt-2.5 leading-[22px]">
            Ti mandiamo un codice via WhatsApp.{'\n'}Niente password.
          </Text>
        </View>

        {/* Phone input */}
        <View className="flex-row gap-2 mb-6">
          <View className="flex-row items-center gap-2 px-4 py-[18px] bg-white dark:bg-dark-surface rounded-2xl shadow-sm">
            <Text>🇮🇹</Text>
            <Text className="font-body-semi text-ink dark:text-cream">+39</Text>
          </View>
          <View className="flex-1 px-5 py-1 bg-white dark:bg-dark-surface rounded-2xl shadow-sm justify-center">
            <TextInput
              className="font-body-semi text-[16px] text-ink dark:text-cream"
              keyboardType="phone-pad"
              placeholder="333 1234 567"
              placeholderTextColor="#7A7E72"
              value={phone}
              onChangeText={setPhone}
              maxLength={14}
              editable={!loading}
              autoComplete="tel"
            />
          </View>
        </View>

        {/* Continue */}
        <Pressable
          disabled={!canContinue}
          onPress={handleContinue}
          className={`w-full py-[17px] rounded-full items-center ${
            canContinue ? 'bg-sage' : 'bg-sage/50'
          }`}
          style={{
            shadowColor: '#6B8068',
            shadowOpacity: canContinue ? 0.32 : 0,
            shadowRadius: 20,
            shadowOffset: { width: 0, height: 6 },
            elevation: canContinue ? 6 : 0,
          }}
        >
          {loading ? (
            <ActivityIndicator color="#ECE3CE" />
          ) : (
            <Text className="font-body-bold text-[15px] text-cream tracking-wide">Continua</Text>
          )}
        </Pressable>

        <Text className="text-[11px] text-ink-muted text-center mt-6 leading-[18px]">
          Continuando accetti i nostri{' '}
          <Text className="text-sage-dark dark:text-sage underline">Termini</Text> e la{' '}
          <Text className="text-sage-dark dark:text-sage underline">Privacy</Text>
        </Text>

        <View className="mt-auto items-center mb-4 pt-10">
          <Text className="text-[12px] text-ink-muted">
            Problemi?{' '}
            <Text className="text-ocra-dark dark:text-ocra font-body-bold">
              Contatta il circolo
            </Text>
          </Text>
        </View>
      </View>
      </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
