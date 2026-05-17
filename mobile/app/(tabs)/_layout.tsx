import { Tabs } from 'expo-router';
import { useColorScheme } from 'react-native';
import { Calendar, Home, User, Users } from 'lucide-react-native';
import { palette } from '@/lib/theme';

export default function TabsLayout() {
  const scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const p = palette[scheme];

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: p.brand.primaryDark,
        tabBarInactiveTintColor: p.text.muted,
        tabBarStyle: {
          backgroundColor: p.bg,
          borderTopColor: p.divider,
          paddingTop: 10,
          paddingBottom: 24,
          height: 84,
        },
        tabBarLabelStyle: {
          fontFamily: 'Manrope_500Medium',
          fontSize: 11,
          marginTop: 2,
        },
        tabBarIconStyle: { marginBottom: 0 },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Home',
          tabBarIcon: ({ color }) => <Home size={22} color={color} strokeWidth={1.5} />,
        }}
      />
      <Tabs.Screen
        name="agenda"
        options={{
          title: 'Agenda',
          tabBarIcon: ({ color }) => <Calendar size={22} color={color} strokeWidth={1.5} />,
        }}
      />
      <Tabs.Screen
        name="club"
        options={{
          title: 'Club',
          tabBarIcon: ({ color }) => <Users size={22} color={color} strokeWidth={1.5} />,
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Io',
          tabBarIcon: ({ color }) => <User size={22} color={color} strokeWidth={1.5} />,
        }}
      />
    </Tabs>
  );
}
