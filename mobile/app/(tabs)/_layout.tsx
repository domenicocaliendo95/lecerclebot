import { Tabs } from 'expo-router';
import { Text } from 'react-native';

export default function TabsLayout() {
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: '#4F6450',
        tabBarInactiveTintColor: '#7A7E72',
        tabBarStyle: {
          backgroundColor: '#FAF4E6',
          borderTopColor: 'rgba(31,36,25,0.08)',
          paddingTop: 8,
          paddingBottom: 24,
          height: 80,
        },
        tabBarLabelStyle: {
          fontFamily: 'Manrope_500Medium',
          fontSize: 11,
        },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Home',
          tabBarIcon: ({ color }) => <Text style={{ color, fontSize: 20 }}>⌂</Text>,
        }}
      />
    </Tabs>
  );
}
